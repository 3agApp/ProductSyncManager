<?php

use App\Actions\Nalda\BuildNaldaProductFeed;
use App\Actions\Nalda\NaldaFeed;
use App\Enums\NaldaListing;
use App\Models\NaldaConnection;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Build the feed and read it back as rows keyed by GTIN.
 *
 * @return array{0: NaldaFeed, 1: array<string, array<string, string>>, 2: array<int, string>}
 */
function buildNaldaFeed(NaldaConnection $connection): array
{
    $stream = fopen('php://temp', 'w+');
    $feed = app(BuildNaldaProductFeed::class)->handle($connection, $stream);
    rewind($stream);

    $header = fgetcsv($stream, escape: '');
    $rows = [];

    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = array_combine($header, $row);
    }

    fclose($stream);

    return [$feed, collect($rows)->keyBy('gtin')->all(), $header];
}

function naldaShop(array $connection = []): array
{
    $shop = Shop::factory()->create();

    return [$shop, NaldaConnection::factory()->for($shop)->create($connection)];
}

test('a listed product is written with its details and the shop defaults', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->branded('Acme')->categorized('Bags > Totes')->create([
        'gtin' => ' 4002725988737 ',
        'name' => 'Canvas Tote',
        'price' => '131.85',
        'stock' => 7,
        'description' => 'A sturdy tote.',
        'image_url' => 'https://example.com/1.jpg',
        'gallery_image_urls' => ['https://example.com/2.jpg', 'https://example.com/3.jpg'],
    ]);

    [$feed, $rows, $header] = buildNaldaFeed($connection);

    expect($header)->toBe(BuildNaldaProductFeed::COLUMNS)
        ->and($feed->listed)->toBe(1)
        ->and($feed->listedGtins)->toBe(['4002725988737'])
        ->and($rows['4002725988737'])->toMatchArray([
            'title' => 'Canvas Tote',
            'country' => 'CH',
            'condition' => 'new',
            'price' => '131.85',
            'tax' => '8.1',
            'currency' => 'CHF',
            'delivery_time_days' => '3',
            'stock' => '7',
            'return_days' => '14',
            'main_image_url' => 'https://example.com/1.jpg',
            'brand' => 'Acme',
            'seller_category' => 'Bags > Totes',
            'description' => 'A sturdy tote.',
            'image_2_url' => 'https://example.com/2.jpg',
            'image_3_url' => 'https://example.com/3.jpg',
            'image_4_url' => '',
            'delete_product' => '',
        ]);
});

test('an out of stock product stays listed so nalda sees the stock drop', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->outOfStock()->create(['gtin' => '111']);

    [$feed, $rows] = buildNaldaFeed($connection);

    expect($feed->listed)->toBe(1)
        ->and($rows['111']['stock'])->toBe('0')
        ->and($rows['111']['delete_product'])->toBe('');
});

test('products without a gtin are left out and counted as skipped', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->create(['gtin' => null]);
    Product::factory()->for($shop)->create(['gtin' => '   ']);
    Product::factory()->for($shop)->create(['gtin' => '222']);

    [$feed, $rows] = buildNaldaFeed($connection);

    expect($rows)->toHaveCount(1)
        ->and($feed->skipped)->toBe(2);
});

test('an excluded product is sent with the delete flag and no stock', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->create(['gtin' => '333', 'stock' => 20, 'nalda_listing' => NaldaListing::Exclude]);

    [$feed, $rows] = buildNaldaFeed($connection);

    expect($feed->listed)->toBe(0)
        ->and($feed->removed)->toBe(1)
        ->and($rows['333']['delete_product'])->toBe('yes')
        ->and($rows['333']['stock'])->toBe('0');
});

test('with exclude all, only products explicitly included are listed', function () {
    [$shop, $connection] = naldaShop(['default_behavior' => 'exclude_all']);

    Product::factory()->for($shop)->create(['gtin' => '444', 'nalda_listing' => NaldaListing::Include]);
    Product::factory()->for($shop)->create(['gtin' => '555']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows['444']['delete_product'])->toBe('')
        ->and($rows['555']['delete_product'])->toBe('yes');
});

test('drafts and archived products are removed even when included', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->draft()->create(['gtin' => '666', 'nalda_listing' => NaldaListing::Include]);
    Product::factory()->for($shop)->archived()->create(['gtin' => '777']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows['666']['delete_product'])->toBe('yes')
        ->and($rows['777']['delete_product'])->toBe('yes');
});

test('a gtin listed before that no product carries any more gets a removal row', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->create(['gtin' => '888']);

    DB::table('nalda_listed_gtins')->insert([
        ['shop_id' => $shop->id, 'gtin' => '888'],
        ['shop_id' => $shop->id, 'gtin' => '999'],
    ]);

    [$feed, $rows] = buildNaldaFeed($connection);

    expect($rows)->toHaveCount(2)
        ->and($rows['888']['delete_product'])->toBe('')
        ->and($rows['999'])->toMatchArray(['delete_product' => 'yes', 'stock' => '0', 'title' => ''])
        ->and($feed->removed)->toBe(1);
});

test('another shop\'s listed gtins are never removed', function () {
    [, $connection] = naldaShop();
    $otherShop = Shop::factory()->create();

    DB::table('nalda_listed_gtins')->insert(['shop_id' => $otherShop->id, 'gtin' => '999']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows)->toBeEmpty();
});

test('when two products share a gtin, the listed one is written once', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->create(['gtin' => '123', 'nalda_listing' => NaldaListing::Exclude, 'name' => 'Stale']);
    Product::factory()->for($shop)->create(['gtin' => '123', 'name' => 'Live']);

    [$feed, $rows] = buildNaldaFeed($connection);

    expect($rows)->toHaveCount(1)
        ->and($rows['123']['title'])->toBe('Live')
        ->and($rows['123']['delete_product'])->toBe('')
        ->and($feed->skipped)->toBe(1);
});
