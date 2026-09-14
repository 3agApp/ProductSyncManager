<?php

use App\Actions\Nalda\StartNaldaExport;
use App\Enums\NaldaListing;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\UploadNaldaProductFeed;
use App\Models\NaldaConnection;
use App\Models\NaldaExport;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Nalda\NaldaSftp;
use App\Services\Nalda\NaldaUploadException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

function listedGtins(Shop $shop): array
{
    return DB::table('nalda_listed_gtins')->where('shop_id', $shop->id)->orderBy('gtin')->pluck('gtin')->all();
}

test('the job uploads the file and remembers what it listed', function () {
    $shop = Shop::factory()->create();
    NaldaConnection::factory()->for($shop)->create();

    Product::factory()->for($shop)->create(['gtin' => '111']);
    Product::factory()->for($shop)->create(['gtin' => '222', 'nalda_listing' => NaldaListing::Exclude]);
    DB::table('nalda_listed_gtins')->insert(['shop_id' => $shop->id, 'gtin' => '333']);

    $uploaded = null;

    $this->mock(NaldaSftp::class)
        ->shouldReceive('upload')
        ->once()
        ->withArgs(function (NaldaConnection $connection, string $fileName, $stream) use (&$uploaded) {
            $uploaded = stream_get_contents($stream);

            return $fileName === 'products.csv';
        });

    $export = $shop->naldaExports()->create(['trigger' => SyncTrigger::Manual, 'status' => SyncRunStatus::Queued]);

    dispatch_sync(new UploadNaldaProductFeed($export));

    $export->refresh();

    expect($export->status)->toBe(SyncRunStatus::Completed)
        ->and($export->listed_products)->toBe(1)
        ->and($export->removed_products)->toBe(2)
        ->and($export->active_shop_id)->toBeNull()
        ->and($uploaded)->toContain('222,')
        ->and($uploaded)->toContain('333,')
        ->and(listedGtins($shop))->toBe(['111'])
        ->and($shop->naldaConnection->refresh()->last_exported_at)->not->toBeNull();
});

test('a refused login fails the export without retrying and keeps the listed gtins', function () {
    $shop = Shop::factory()->create();
    NaldaConnection::factory()->for($shop)->create();
    Product::factory()->for($shop)->create(['gtin' => '111']);
    DB::table('nalda_listed_gtins')->insert(['shop_id' => $shop->id, 'gtin' => '999']);

    $this->mock(NaldaSftp::class)
        ->shouldReceive('upload')
        ->once()
        ->andThrow(NaldaUploadException::authentication());

    $export = $shop->naldaExports()->create(['trigger' => SyncTrigger::Manual, 'status' => SyncRunStatus::Queued]);

    dispatch_sync(new UploadNaldaProductFeed($export));

    expect($export->refresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($export->message)->toBe('Nalda rejected the SFTP username or password.')
        ->and(listedGtins($shop))->toBe(['999']);
});

test('an unreachable server leaves the listed gtins untouched', function () {
    $shop = Shop::factory()->create();
    NaldaConnection::factory()->for($shop)->create();
    Product::factory()->for($shop)->create(['gtin' => '111']);
    DB::table('nalda_listed_gtins')->insert(['shop_id' => $shop->id, 'gtin' => '999']);

    $this->mock(NaldaSftp::class)
        ->shouldReceive('upload')
        ->andThrow(NaldaUploadException::unreachable());

    $export = $shop->naldaExports()->create(['trigger' => SyncTrigger::Manual, 'status' => SyncRunStatus::Queued]);

    expect(fn () => dispatch_sync(new UploadNaldaProductFeed($export)))->toThrow(NaldaUploadException::class);

    expect(listedGtins($shop))->toBe(['999']);
});

test('a shop has one active export at a time', function () {
    Bus::fake();

    $shop = Shop::factory()->create();
    NaldaConnection::factory()->for($shop)->create();

    $start = app(StartNaldaExport::class);

    expect($start->handle($shop, SyncTrigger::Manual))->toBeInstanceOf(NaldaExport::class)
        ->and($start->handle($shop, SyncTrigger::Manual))->toBeNull();
});

test('a stalled export is failed by the scheduler', function () {
    $shop = Shop::factory()->create();
    $export = NaldaExport::factory()->for($shop)->queued()->create();
    $export->forceFill(['created_at' => now()->subHours(2)])->save();

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    expect($export->refresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($export->message)->toBe('The upload to Nalda timed out.');
});
