<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a remote store address the application will fetch from.
 *
 * Because the address is user supplied and the request is made from inside
 * the network, this also blocks the private and loopback ranges that make
 * up the usual server side request forgery targets.
 */
class StoreUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail(__('Enter a valid store address, including https://'));

            return;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = (string) parse_url($value, PHP_URL_HOST);

        if ($host === '') {
            $fail(__('Enter a valid store address, including https://'));

            return;
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail(__('The store address must start with https://'));

            return;
        }

        // WooCommerce only accepts key and secret authentication over TLS, so
        // plain HTTP is allowed locally for testing and nowhere else.
        if ($scheme !== 'https' && ! app()->environment('local')) {
            $fail(__('The store address must start with https://'));

            return;
        }

        if ($this->isPrivate($host) && ! app()->environment('local', 'testing')) {
            $fail(__('The store address must be a public address.'));
        }
    }

    /**
     * Determine if the host points somewhere inside the local network.
     */
    private function isPrivate(string $host): bool
    {
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            // Unresolvable hosts are left to the HTTP client to fail on.
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
