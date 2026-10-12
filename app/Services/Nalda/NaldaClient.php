<?php

namespace App\Services\Nalda;

use App\Models\NaldaConnection;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads orders from Nalda's seller API.
 *
 * Every call carries the shop's own API key, created in the Nalda Seller
 * Portal under Orders → Settings. Nalda wraps every answer in
 * {success, message, result}.
 */
class NaldaClient
{
    public function __construct(private readonly NaldaConnection $connection)
    {
        //
    }

    /**
     * Check that the API answers and accepts the key.
     *
     * The health check does not need a key, so an order list over one day is
     * what proves the key works.
     *
     * @throws NaldaApiException
     */
    public function check(): void
    {
        $this->orderItems(now()->subDay(), now());
    }

    /**
     * Get every order line placed between two dates, inclusive.
     *
     * Nalda answers one row per article, each carrying its order's details;
     * the order's shipping fee is not among them (see order()).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws NaldaApiException
     */
    public function orderItems(CarbonInterface $from, CarbonInterface $to): array
    {
        $result = $this->result($this->send('POST', 'orders/items', [
            'range' => 'custom',
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]));

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * Get one order's own details, which carry the shipping fee.
     *
     * `fee` is what the buyer paid for shipping and `commission` is Nalda's
     * commission on that fee; the lines' commission is on the articles.
     *
     * @return array<string, mixed>
     *
     * @throws NaldaApiException
     */
    public function order(int $orderId): array
    {
        return $this->result($this->send('GET', 'orders/'.$orderId));
    }

    /**
     * Send a request and turn failures into exceptions the jobs can act on.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws NaldaApiException
     */
    private function send(string $method, string $path, array $body = []): Response
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.nalda.api_url'), '/'))
                ->timeout(max(1, (int) config('services.nalda.api_timeout')))
                ->acceptJson()
                ->withHeaders(['X-API-KEY' => (string) $this->connection->api_key])
                ->send($method, $path, $method === 'GET' ? [] : ['json' => $body]);
        } catch (ConnectionException $exception) {
            throw NaldaApiException::unreachable($exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw NaldaApiException::authentication();
        }

        if ($response->status() === 429) {
            throw NaldaApiException::rateLimited();
        }

        if ($response->serverError()) {
            throw NaldaApiException::unreachable();
        }

        return $response;
    }

    /**
     * Read the result out of Nalda's wrapper.
     *
     * @return array<mixed>
     *
     * @throws NaldaApiException
     */
    private function result(Response $response): array
    {
        $json = $response->json();

        // An empty range is not an error, but Nalda answers it as one.
        if (is_array($json) && ($json['success'] ?? null) === false && preg_match('/no orders? found/i', (string) ($json['message'] ?? '')) === 1) {
            return [];
        }

        if (! is_array($json) || $response->failed() || ($json['success'] ?? false) !== true) {
            throw NaldaApiException::rejected(is_array($json) ? (string) ($json['message'] ?? '') : '');
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }
}
