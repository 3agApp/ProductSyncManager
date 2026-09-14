<?php

namespace App\Services\Temu;

use App\Models\TemuConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Calls Temu's open platform gateway.
 *
 * Every call is a POST of one JSON object to the same address: the method
 * name goes in "type", next to the app key, access token, a timestamp and a
 * signature over all of it.
 */
class TemuClient
{
    /**
     * The code Temu sends with a successful response.
     */
    private const int SUCCESS_CODE = 1000000;

    /**
     * Flags used both to sign nested values and to send the body, so the
     * signed text is exactly the text Temu reads.
     */
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function __construct(private readonly TemuConnection $connection)
    {
        //
    }

    /**
     * Call a gateway method and return its result.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TemuException
     */
    public function call(string $type, array $params = []): array
    {
        $body = [
            ...array_filter($params, fn (mixed $value) => $value !== null),
            'type' => $type,
            'app_key' => (string) $this->connection->app_key,
            'access_token' => (string) $this->connection->access_token,
            'data_type' => 'JSON',
            'timestamp' => now()->getTimestamp(),
        ];

        $body['sign'] = self::sign($body, (string) $this->connection->app_secret);

        try {
            $response = Http::timeout(max(1, (int) config('services.temu.timeout')))
                ->acceptJson()
                ->withBody(json_encode($body, self::JSON_FLAGS), 'application/json')
                ->post((string) config('services.temu.base_url'));
        } catch (ConnectionException $exception) {
            throw TemuException::unreachable($exception);
        }

        if ($response->status() === 429) {
            throw TemuException::rateLimited();
        }

        if ($response->serverError()) {
            throw TemuException::unreachable();
        }

        $json = $response->json();

        if (! is_array($json) || ! array_key_exists('success', $json)) {
            throw TemuException::unexpected();
        }

        if ($json['success'] !== true || (int) ($json['errorCode'] ?? self::SUCCESS_CODE) !== self::SUCCESS_CODE) {
            throw $this->failure((string) ($json['errorMsg'] ?? ''), isset($json['errorCode']) ? (int) $json['errorCode'] : null);
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }

    /**
     * Sign the request parameters the way Temu's gateway checks them.
     *
     * Keys are sorted, each key is followed directly by its value (nested
     * values as compact JSON), and the whole string is wrapped in the app
     * secret, hashed with MD5 and uppercased.
     *
     * @param  array<string, mixed>  $params
     */
    public static function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params, SORT_STRING);

        $signed = $secret;

        foreach ($params as $key => $value) {
            $signed .= $key.self::stringValue($value);
        }

        return strtoupper(md5($signed.$secret));
    }

    /**
     * Write a value as it appears in the signed string.
     */
    private static function stringValue(mixed $value): string
    {
        return match (true) {
            is_array($value) => json_encode($value, self::JSON_FLAGS),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * Turn a refused response into the matching exception.
     *
     * Temu does not document its gateway error codes in one place, so
     * credential and rate problems are recognised by what the message says.
     */
    private function failure(string $message, ?int $code): TemuException
    {
        if (preg_match('/rate ?limit|too many|frequen|qps/i', $message) === 1) {
            return TemuException::rateLimited();
        }

        if (preg_match('/access.?token|app.?key|sign(ature)?\b|unauthori|not authori|authoriz|expired|invalid token/i', $message) === 1) {
            return TemuException::authentication($message, $code);
        }

        return TemuException::rejected($message, $code);
    }
}
