<?php

namespace App\Services\WooCommerce;

use App\Models\WooCommerceConnection;
use App\Services\Network\PublicHostGuard;
use App\Services\Network\UnsafeDestinationException;
use App\Services\WooCommerce\Exceptions\WooCommerceAuthenticationException;
use App\Services\WooCommerce\Exceptions\WooCommerceDestinationException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use App\Services\WooCommerce\Exceptions\WooCommerceWebhookSetupException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class WooCommerceClient
{
    /**
     * When webhook requests must stop, so a caller can bound its total time.
     */
    private ?CarbonInterface $deadline = null;

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
     * Get a copy whose webhook requests all finish by the deadline.
     */
    public function until(CarbonInterface $deadline): self
    {
        $client = clone $this;
        $client->deadline = $deadline;

        return $client;
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
                    throw new WooCommerceWebhookSetupException(__('WooCommerce returned an invalid webhook list. No new webhooks were created.'));
                }
            }
            $webhooks = [...$webhooks, ...$items];
            if (count(array_unique(array_column($webhooks, 'id'))) !== count($webhooks)) {
                throw new WooCommerceWebhookSetupException(__('WooCommerce returned duplicate webhook pages. No new webhooks were created. Please try again.'));
            }
            $pages = $this->intHeader($response, 'X-WP-TotalPages');
            if ($pages !== null ? $page >= $pages : count($items) < 100) {
                $total = $this->intHeader($response, 'X-WP-Total');
                if ($total !== null && count($webhooks) !== $total) {
                    throw new WooCommerceWebhookSetupException(__('The webhook list changed during setup. Please try again.'));
                }

                return $webhooks;
            }
            if ($items === []) {
                break;
            }
        }

        throw new WooCommerceWebhookSetupException(__('Could not read the complete webhook list. No new webhooks were created. Please try again.'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveWebhook(?int $id, array $data): array
    {
        $response = $this->webhookRequest($id === null ? 'POST' : 'PUT', $id === null ? 'webhooks' : 'webhooks/'.$id, $data);
        $webhook = $response->json();

        if (! $this->confirmsActiveWebhook($webhook, $id, $data)) {
            throw new WooCommerceWebhookSetupException(__('WooCommerce did not confirm an active webhook. Try setup again to check and finish any partially created webhooks.'));
        }

        return $webhook;
    }

    /**
     * Determine if a save response describes the active webhook that was asked for.
     *
     * @param  array<string, mixed>  $data
     *
     * @phpstan-assert-if-true array<string, mixed> $webhook
     */
    private function confirmsActiveWebhook(mixed $webhook, ?int $id, array $data): bool
    {
        return is_array($webhook)
            && is_int($webhook['id'] ?? null) && $webhook['id'] > 0
            && ($id === null || $webhook['id'] === $id)
            && ($webhook['topic'] ?? null) === $data['topic']
            && ($webhook['delivery_url'] ?? null) === $data['delivery_url']
            && ($webhook['status'] ?? null) === 'active';
    }

    /**
     * Switch a webhook off without deleting it.
     */
    public function disableWebhook(int $id): void
    {
        $webhook = $this->webhookRequest('PUT', 'webhooks/'.$id, ['status' => 'disabled'])->json();

        if (! is_array($webhook) || ($webhook['id'] ?? null) !== $id || ($webhook['status'] ?? null) !== 'disabled') {
            throw new WooCommerceWebhookSetupException(__('WooCommerce did not confirm that a duplicate webhook was disabled. Try setup again.'));
        }
    }

    /** @param array<string, mixed> $data */
    private function webhookRequest(string $method, string $path, array $data): Response
    {
        // Never automatically retry a POST: it may have succeeded before the
        // response was lost. The next setup lists remote hooks before writing.
        $request = $this->request()->retry(1, 0, throw: false);

        if ($this->deadline !== null) {
            $remaining = now()->diffInSeconds($this->deadline, false);

            if ($remaining <= 0) {
                throw new WooCommerceWebhookSetupException(__('WooCommerce is responding too slowly and setup ran out of time. Try again; existing webhooks will be reused.'));
            }

            $request->connectTimeout(min($this->quick ? 5 : 10, $remaining))
                ->timeout(min($this->quick ? 10 : 30, $remaining));
        }

        try {
            $response = $request->send($method, $path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException $exception) {
            throw new WooCommerceWebhookSetupException(__('WooCommerce could not be reached or setup timed out. Try again; existing webhooks will be reused.'), previous: $exception);
        }

        if ($this->lacksWebhookPermission($response)) {
            throw new WooCommerceWebhookSetupException(__('This API key does not have permission to manage webhooks. Save a Read/Write key belonging to a WooCommerce user who can manage webhooks, then try again.'));
        }

        if ($response->status() === 401) {
            throw new WooCommerceAuthenticationException('WooCommerce rejected the API credentials.');
        }

        if ($response->status() === 429) {
            throw new WooCommerceWebhookSetupException(__('WooCommerce is limiting requests. Wait a moment, then try setup again.'));
        }

        if (! $response->successful()) {
            throw new WooCommerceWebhookSetupException(__('WooCommerce could not complete webhook setup. Check that this app’s public HTTPS address is reachable, then try again. Existing setup progress is kept.'));
        }

        return $response;
    }

    /**
     * Determine if WooCommerce refused because the key may not manage webhooks.
     *
     * WooCommerce reports a key without write access as a 401 whose message
     * mentions permissions, so that case is told apart from bad credentials by
     * its message rather than its status.
     */
    private function lacksWebhookPermission(Response $response): bool
    {
        $forbiddenCodes = ['woocommerce_rest_cannot_view', 'woocommerce_rest_cannot_create', 'woocommerce_rest_cannot_edit'];

        if ($response->status() === 403 || in_array($response->json('code'), $forbiddenCodes, true)) {
            return true;
        }

        $message = $response->json('message');

        return $response->status() === 401 && is_string($message) && str_contains(strtolower($message), 'permission');
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
     * Read whether the store's prices are entered with tax included.
     *
     * Null means the store gave no recognisable answer, so the caller keeps
     * what it already has.
     */
    public function pricesIncludeTax(): ?bool
    {
        return match ($this->get('settings/tax/woocommerce_prices_include_tax', [])->json('value')) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /**
     * Read the store's currency, as a three-letter code.
     *
     * Null means the store gave no recognisable answer, so the caller keeps
     * what it already has.
     */
    public function currency(): ?string
    {
        $currency = $this->get('settings/general/woocommerce_currency', [])->json('value');

        return is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) === 1
            ? strtoupper($currency)
            : null;
    }

    /**
     * Read the store's standard tax rate, as a percentage with two decimals.
     *
     * A store can hold a rate per country; a Swiss rate is preferred, then
     * one that applies to every country, then whichever comes first.
     */
    public function standardTaxRate(): ?string
    {
        $rates = $this->get('taxes', ['class' => 'standard', 'per_page' => 100])->json();

        if (! is_array($rates)) {
            return null;
        }

        $rates = array_values(array_filter(
            $rates,
            fn (mixed $rate) => is_array($rate) && is_numeric($rate['rate'] ?? null),
        ));

        usort($rates, fn (array $a, array $b) => $this->countryRank($a) <=> $this->countryRank($b));

        return $rates === [] ? null : number_format((float) $rates[0]['rate'], 2, '.', '');
    }

    /**
     * Rank a tax rate by how well its country fits a Swiss marketplace.
     *
     * @param  array<string, mixed>  $rate
     */
    private function countryRank(array $rate): int
    {
        return match (strtoupper((string) ($rate['country'] ?? ''))) {
            'CH' => 0,
            '' => 1,
            default => 2,
        };
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
            ->withOptions($this->pinnedDestination())
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
     * Pin the request to store addresses that were checked just now.
     *
     * Validating the address when the connection was saved is not enough: DNS
     * can answer differently by the time a worker connects. The host is
     * resolved again here, refused if any address is private, and curl is told
     * to connect only to those addresses. TLS still verifies the hostname.
     *
     * @return array<string, mixed>
     *
     * @throws WooCommerceDestinationException
     * @throws WooCommerceRequestException
     */
    private function pinnedDestination(): array
    {
        if (config('services.woocommerce.allow_private_hosts')) {
            return [];
        }

        $url = $this->connection->store_url;
        $host = (string) parse_url($url, PHP_URL_HOST);

        try {
            $addresses = app(PublicHostGuard::class)->publicAddresses($host);
        } catch (UnsafeDestinationException $exception) {
            throw new WooCommerceDestinationException($exception->getMessage(), previous: $exception);
        }

        if ($addresses === []) {
            throw new WooCommerceRequestException("The store host {$host} could not be resolved.");
        }

        // A proxy from HTTP_PROXY or HTTPS_PROXY would make the connection to
        // the proxy instead and resolve the store on the far side, where the
        // pin does not reach. An empty proxy is Guzzle's final "no proxy".
        $direct = ['proxy' => ''];

        // An IP literal is never looked up, so there is nothing to pin.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return $direct;
        }

        $port = parse_url($url, PHP_URL_PORT)
            ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443);

        $pinned = implode(',', array_map(
            fn (string $address) => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses,
        ));

        return [...$direct, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]]];
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
