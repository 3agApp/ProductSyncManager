<?php

namespace Tests;

use App\Services\Network\HostResolver;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * A public address every host resolves to unless a test says otherwise.
     */
    protected const string PUBLIC_ADDRESS = '93.184.215.14';

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never touch real DNS.
        $this->resolveHostsTo([self::PUBLIC_ADDRESS]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Answer every host lookup with the given addresses.
     *
     * @param  array<int, string>|Closure(string): array<int, string>  $addresses
     */
    protected function resolveHostsTo(array|Closure $addresses): void
    {
        $answer = $addresses instanceof Closure ? $addresses : fn () => $addresses;

        $this->app->instance(HostResolver::class, new class($answer) extends HostResolver
        {
            /**
             * @param  Closure(string): array<int, string>  $answer
             */
            public function __construct(private readonly Closure $answer)
            {
                //
            }

            public function resolve(string $host): array
            {
                return ($this->answer)($host);
            }
        });
    }
}
