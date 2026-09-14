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

    // Nalda's sample file header, column for column.
    expect(implode(',', $header))->toBe('gtin,title,country,condition,price,tax,currency,delivery_time_days,stock,return_days,main_image_url,brand,category,google_category,seller_category,description,length_mm,width_mm,height_mm,weight_g,shipping_length_mm,shipping_width_mm,shipping_height_mm,shipping_weight_g,volume_ml,size,colour,image_2_url,image_3_url,image_4_url,image_5_url,delete_product,author,language,format,year,publisher')
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
            // Filed under the nested Totes only: its name, not its path.
            'seller_category' => 'Totes',
            'description' => 'A sturdy tote.',
            'image_2_url' => 'https://example.com/2.jpg',
            'image_3_url' => 'https://example.com/3.jpg',
            'image_4_url' => '',
            'category' => '',
            'weight_g' => '',
            'publisher' => '',
            'delete_product' => '',
        ]);
});

test('the seller category joins every category name, ordered by name', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->categorized('Toys > Puzzles', 'Games', 'Adventskalender')->create(['gtin' => '108']);
    Product::factory()->for($shop)->create(['gtin' => '109']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows['108']['seller_category'])->toBe('Adventskalender > Games > Puzzles')
        ->and($rows['109']['seller_category'])->toBe('');
});

test('prices are always written with two decimals', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->create(['gtin' => '100', 'price' => '11.6']);
    Product::factory()->for($shop)->create(['gtin' => '101', 'price' => '25']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows['100']['price'])->toBe('11.60')
        ->and($rows['101']['price'])->toBe('25.00')
        ->and($rows['100']['tax'])->toBe('8.1');
});

test('the tax column is the accepted rate closest to the shop rate', function (string $shopRate, string $sent) {
    $shop = Shop::factory()->create(['tax_rate' => $shopRate]);
    $connection = NaldaConnection::factory()->for($shop)->create();

    Product::factory()->for($shop)->create(['gtin' => '103']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows['103']['tax'])->toBe($sent);
})->with([
    'standard' => ['8.1', '8.1'],
    'old standard' => ['7.7', '8.1'],
    'reduced' => ['2.6', '2.6'],
    'old reduced' => ['2.5', '2.6'],
    'none' => ['0', '8.1'],
]);

test('prices entered with tax are sent as they are, whatever the price mode', function () {
    $shop = Shop::factory()->create(['prices_include_tax' => true, 'tax_rate' => '8.1']);
    $connection = NaldaConnection::factory()->for($shop)->create(['price_mode' => 'add_tax']);

    Product::factory()->for($shop)->create(['gtin' => '104', 'price' => '20.00']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows['104']['price'])->toBe('20.00');
});

test('prices entered without tax get vat added and rounded to five centimes', function () {
    $shop = Shop::factory()->create(['prices_include_tax' => false, 'tax_rate' => '8.1']);
    $connection = NaldaConnection::factory()->for($shop)->create(['price_mode' => 'add_tax']);

    Product::factory()->for($shop)->create(['gtin' => '105', 'price' => '20.00']);
    Product::factory()->for($shop)->create(['gtin' => '106', 'price' => '9.99']);

    [, $rows] = buildNaldaFeed($connection);

    // 20.00 * 1.081 = 21.62 and 9.99 * 1.081 = 10.799
    expect($rows['105']['price'])->toBe('21.60')
        ->and($rows['106']['price'])->toBe('10.80');
});

test('prices entered without tax can still be sent as they are', function () {
    $shop = Shop::factory()->create(['prices_include_tax' => false, 'tax_rate' => '8.1']);
    $connection = NaldaConnection::factory()->for($shop)->create(['price_mode' => 'as_is']);

    Product::factory()->for($shop)->create(['gtin' => '107', 'price' => '20.00']);

    [, $rows] = buildNaldaFeed($connection);

    expect($rows['107']['price'])->toBe('20.00');
});

test('a product without a price is removed from nalda', function () {
    [$shop, $connection] = naldaShop();

    Product::factory()->for($shop)->create(['gtin' => '102', 'price' => '0', 'stock' => 5]);

    [$feed, $rows] = buildNaldaFeed($connection);

    expect($feed->listed)->toBe(0)
        ->and($rows['102']['delete_product'])->toBe('yes')
        ->and($rows['102']['stock'])->toBe('0');
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
