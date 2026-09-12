<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceAuthenticationException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
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
