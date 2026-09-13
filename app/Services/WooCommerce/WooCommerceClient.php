<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceAuthenticationException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use App\Services\WooCommerce\Exceptions\WooCommerceWebhookSetupException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class WooCommerceClient
{
    public function __construct(
        private readonly WooCommerceConnection $connection,
        private readonly bool $quick = false,
    ) {
        //
    }

    /**
     * Build a client that fails fast, for interactive connection tests.
     */
    public static function quick(WooCommerceConnection $connection): self
    {
        return new self($connection, quick: true);
    }

    /** @return array<int, array<string, mixed>> */
    public function webhooks(): array
    {
        $webhooks = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = $this->webhookRequest('GET', 'webhooks', ['page' => $page, 'per_page' => 100, 'status' => 'all']);
            $items = $this->catalogItems($response);
            foreach ($items as $item) {
                if (! is_string($item['topic'] ?? null) || ! is_string($item['delivery_url'] ?? null)) {
                    throw new WooCommerceWebhookSetupException('WooCommerce returned an invalid webhook list. No new webhooks were created.');
                }
            }
            $webhooks = [...$webhooks, ...$items];
            if (count(array_unique(array_column($webhooks, 'id'))) !== count($webhooks)) {
                throw new WooCommerceWebhookSetupException('WooCommerce returned duplicate webhook pages. No new webhooks were created. Please try again.');
            }
            $pages = $this->intHeader($response, 'X-WP-TotalPages');
            if ($pages !== null ? $page >= $pages : count($items) < 100) {
                $total = $this->intHeader($response, 'X-WP-Total');
                if ($total !== null && count($webhooks) !== $total) {
                    throw new WooCommerceWebhookSetupException('The webhook list changed during setup. Please try again.');
                }

                return $webhooks;
            }
            if ($items === []) {
                break;
            }
        }

        throw new WooCommerceWebhookSetupException('Could not read the complete webhook list. No new webhooks were created. Please try again.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveWebhook(?int $id, array $data): array
    {
        $response = $this->webhookRequest($id === null ? 'POST' : 'PUT', $id === null ? 'webhooks' : 'webhooks/'.$id, $data);
        $webhook = $response->json();
        if (! is_array($webhook) || ! is_int($webhook['id'] ?? null) || $webhook['id'] <= 0
            || ($id !== null && $webhook['id'] !== $id)
            || ($webhook['topic'] ?? null) !== $data['topic']
            || ($webhook['delivery_url'] ?? null) !== $data['delivery_url']
            || ($webhook['status'] ?? null) !== 'active') {
            throw new WooCommerceWebhookSetupException('WooCommerce did not confirm an active webhook. Try setup again to check and finish any partially created webhooks.');
        }

        return $webhook;
    }

    /** @param array<string, mixed> $data */
    private function webhookRequest(string $method, string $path, array $data): Response
    {
        try {
            // Never automatically retry a POST: it may have succeeded before the
            // response was lost. The next setup lists remote hooks before writing.
            $response = $this->request()->retry(1, 0, throw: false)
                ->send($method, $path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException $exception) {
            throw new WooCommerceWebhookSetupException('WooCommerce could not be reached or setup timed out. Try again; existing webhooks will be reused.', previous: $exception);
        }

        $code = $response->json('code');
        $message = $response->json('message');
        if ($response->status() === 403 || in_array($code, ['woocommerce_rest_cannot_view', 'woocommerce_rest_cannot_create', 'woocommerce_rest_cannot_edit'], true)
            || ($response->status() === 401 && is_string($message) && str_contains(strtolower($message), 'permission'))) {
            throw new WooCommerceWebhookSetupException('This API key does not have permission to manage webhooks. Save a Read/Write key belonging to a WooCommerce user who can manage webhooks, then try again.');
        }
        if ($response->status() === 401) {
            throw new WooCommerceAuthenticationException('WooCommerce rejected the API credentials.');
        }
        if ($response->status() === 429) {
            throw new WooCommerceWebhookSetupException('WooCommerce is limiting requests. Wait a moment, then try setup again.');
        }
        if (! $response->successful()) {
            throw new WooCommerceWebhookSetupException('WooCommerce could not complete webhook setup. Check that this app’s public HTTPS address is reachable, then try again. Existing setup progress is kept.');
        }

        return $response;
    }

    /**
     * Fetch the current state of up to a hundred products, in any status.
     *
     * A product the store no longer has is absent from the list, which is the
     * answer the single-product endpoint gives with its 404. Error pages and
     * malformed lists throw rather than reading as "nothing exists".
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function products(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $response = $this->get('products', [
            'include' => implode(',', $ids),
            'per_page' => count($ids),
            'status' => 'any',
        ]);

        /** @var array<int, array<string, mixed>> $products */
        $products = $this->catalogItems($response);

        foreach ($products as $product) {
            if (! in_array($product['id'], $ids, true)
                || ! is_string($product['status'] ?? null) || ! is_string($product['type'] ?? null)) {
                throw new WooCommerceRequestException('WooCommerce returned an invalid product.');
            }
        }

        return $products;
    }

    /**
     * Fetch one page of published simple products, oldest first.
     *
     * Unpublished and variable products are excluded at the API level, so
     * the reported totals only ever count products this app will import.
     */
    public function productPage(int $page, ?int $perPage = null): WooCommerceProductPage
    {
        $response = $this->get('products', [
            'page' => $page,
            'per_page' => $perPage ?? (int) config('services.woocommerce.per_page'),
            'type' => 'simple',
            'status' => 'publish',
            'orderby' => 'id',
            'order' => 'asc',
        ]);

        /** @var array<int, array<string, mixed>> $products */
        $products = $this->catalogItems($response);

        return new WooCommerceProductPage(
            products: $products,
            total: $this->intHeader($response, 'X-WP-Total'),
            totalPages: $this->intHeader($response, 'X-WP-TotalPages'),
        );
    }

    /**
     * Fetch one page of product categories.
     *
     * The product payload names a product's categories but not how they
     * nest, so the tree is read from this endpoint instead.
     */
    public function categoryPage(int $page, ?int $perPage = null): WooCommerceCategoryPage
    {
        $response = $this->get('products/categories', [
            'page' => $page,
            'per_page' => $perPage ?? (int) config('services.woocommerce.per_page'),
            'orderby' => 'id',
            'order' => 'asc',
        ]);

        /** @var array<int, array<string, mixed>> $categories */
        $categories = $this->catalogItems($response);

        return new WooCommerceCategoryPage(
            categories: $categories,
            totalPages: $this->intHeader($response, 'X-WP-TotalPages'),
        );
    }

    /**
     * Get the number of importable products, verifying the credentials work.
     */
    public function ping(): int
    {
        return $this->countProducts('simple');
    }

    /**
     * Get the number of variable products, which this app does not import.
     */
    public function variableProductCount(): int
    {
        return $this->countProducts('variable');
    }

    /**
     * Count published remote products of a given type using the headers.
     */
    private function countProducts(string $type): int
    {
        $response = $this->get('products', [
            'page' => 1,
            'per_page' => 1,
            'type' => $type,
            'status' => 'publish',
        ]);

        return $this->intHeader($response, 'X-WP-Total') ?? 0;
    }

    /**
     * Send a GET request and translate failures into typed exceptions.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws WooCommerceAuthenticationException
     * @throws WooCommerceRateLimitException
     * @throws WooCommerceRequestException
     */
    private function get(string $path, array $query): Response
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $exception) {
            throw new WooCommerceRequestException($exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new WooCommerceAuthenticationException('WooCommerce rejected the API credentials.');
        }

        if ($response->status() === 429) {
            throw WooCommerceRateLimitException::retryAfter(
                (int) ($response->header('Retry-After') ?: 60),
            );
        }

        if (! $response->successful()) {
            throw WooCommerceRequestException::status($response->status(), $response->body());
        }

        return $response;
    }

    /**
     * Reject error pages and malformed catalogs instead of treating them as empty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogItems(Response $response): array
    {
        $items = json_decode($response->body());

        if (! is_array($items)) {
            throw new WooCommerceRequestException('WooCommerce did not return a catalog array.');
        }

        foreach ($items as $item) {
            if (! is_object($item) || ! isset($item->id) || ! is_int($item->id) || $item->id <= 0) {
                throw new WooCommerceRequestException('WooCommerce returned an invalid catalog item.');
            }
        }

        return $response->json();
    }

    /**
     * Build the configured HTTP client for this connection.
     */
    private function request(): PendingRequest
    {
        $baseUrl = rtrim($this->connection->store_url, '/').'/wp-json/wc/v3';

        return Http::baseUrl($baseUrl)
            ->withBasicAuth($this->connection->consumer_key, $this->connection->consumer_secret)
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout($this->quick ? 5 : 10)
            ->timeout($this->quick ? 10 : 30)
            ->retry(
                $this->quick ? 1 : (int) config('services.woocommerce.retries'),
                500,
                $this->shouldRetry(...),
                throw: false,
            );
    }

    /**
     * Decide whether a failed attempt is worth repeating.
     *
     * Only transient failures are retried. Rejected credentials and rate
     * limits are handled by the caller, which fails or releases the job
     * instead of hammering the store.
     */
    private function shouldRetry(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && $exception->response->serverError();
    }

    /**
     * Read an integer response header, or null when the host stripped it.
     */
    private function intHeader(Response $response, string $header): ?int
    {
        $value = $response->header($header);

        return $value === '' ? null : (int) $value;
    }
}
