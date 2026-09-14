<?php

use App\Enums\NaldaDefaultBehavior;
use App\Enums\NaldaListing;
use App\Enums\ShopRole;
use App\Jobs\UploadNaldaProductFeed;
use App\Models\NaldaConnection;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Nalda\NaldaSftp;
use App\Services\Nalda\NaldaUploadException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function naldaSettings(array $overrides = []): array
{
    return [
        'sftp_username' => 'nalda-seller-abc123',
        'sftp_password' => 'super-secret-password',
        'file_name' => 'products.csv',
        'default_behavior' => 'include_all',
        'country' => 'ch',
        'currency' => 'chf',
        'condition' => 'new',
        'price_mode' => 'as_is',
        'delivery_time_days' => 3,
        'return_days' => 14,
        'upload_after_sync' => '1',
        ...$overrides,
    ];
}

function naldaMember(ShopRole $role): array
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($user, ['role' => $role->value]);

    return [$user, $shop];
}

test('the nalda page starts from the swiss defaults', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('shops.nalda.edit', $user->currentShop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shops/nalda')
            ->where('connection.isConfigured', false)
            ->where('connection.country', 'CH')
            ->where('connection.currency', 'CHF')
            ->where('connection.priceMode', 'as_is')
            ->where('shopTax.pricesIncludeTax', true)
            ->where('shopTax.naldaTaxRate', '8.1')
            ->where('connection.deliveryTimeDays', 3)
            ->where('connection.returnDays', 14)
            ->where('connection.sftpHost', 'sftp.nalda.com')
            ->where('connection.sftpPort', 2022)
            ->has('behaviors', 2)
            ->where('currentExport', null));
});

test('an owner can save the nalda settings, with the password encrypted', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->from(route('shops.nalda.edit', $shop))
        ->patch(route('shops.nalda.update', $shop), naldaSettings())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('shops.nalda.edit', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Nalda settings saved.']);

    $connection = $shop->naldaConnection()->sole();
    $raw = DB::table('nalda_connections')->where('shop_id', $shop->id)->first();

    expect($connection->sftp_password)->toBe('super-secret-password')
        ->and($raw->sftp_password)->not->toBe('super-secret-password')
        ->and($connection->country)->toBe('CH')
        ->and($connection->currency)->toBe('CHF')
        ->and($connection->default_behavior)->toBe(NaldaDefaultBehavior::IncludeAll)
        ->and($connection->upload_after_sync)->toBeTrue();

    $this->get(route('shops.nalda.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.hasPassword', true)
            ->where('connection.isUsable', true)
            ->missing('connection.sftpPassword'));
});

test('a blank password keeps the stored one', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    NaldaConnection::factory()->for($shop)->create(['sftp_password' => 'original']);

    $this->actingAs($user)
        ->patch(route('shops.nalda.update', $shop), naldaSettings(['sftp_password' => '', 'default_behavior' => 'exclude_all']))
        ->assertSessionHasNoErrors();

    $connection = $shop->naldaConnection()->sole();

    expect($connection->sftp_password)->toBe('original')
        ->and($connection->default_behavior)->toBe(NaldaDefaultBehavior::ExcludeAll);
});

test('settings can be saved without sftp credentials, for downloading by hand', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->patch(route('shops.nalda.update', $shop), naldaSettings(['sftp_username' => '', 'sftp_password' => '']))
        ->assertSessionHasNoErrors();

    expect($shop->naldaConnection()->sole()->hasUsableCredentials())->toBeFalse();
});

test('a first save needs a password with the user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('shops.nalda.update', $user->currentShop), naldaSettings(['sftp_password' => '']))
        ->assertSessionHasErrors('sftp_password');
});

test('the defaults are validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('shops.nalda.update', $user->currentShop), naldaSettings([
            'country' => 'CHE',
            'price_mode' => 'sometimes',
            'delivery_time_days' => 0,
            'default_behavior' => 'sometimes',
        ]))
        ->assertSessionHasErrors(['country', 'price_mode', 'delivery_time_days', 'default_behavior']);
});

test('the file name stays a csv in the sftp root', function (string $fileName) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('shops.nalda.update', $user->currentShop), naldaSettings(['file_name' => $fileName]))
        ->assertSessionHasErrors('file_name');
})->with(['../products.csv', 'feeds/products.csv', 'products.txt', '.csv', '']);

test('a custom file name and price mode are saved', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $shop->update(['prices_include_tax' => false, 'tax_rate' => '2.5']);

    $this->actingAs($user)
        ->patch(route('shops.nalda.update', $shop), naldaSettings(['file_name' => 'toysonline_products.csv', 'price_mode' => 'add_tax']))
        ->assertSessionHasNoErrors();

    $connection = $shop->naldaConnection()->sole();

    expect($connection->file_name)->toBe('toysonline_products.csv')
        ->and($connection->price_mode->value)->toBe('add_tax');

    $this->get(route('shops.nalda.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.fileName', 'toysonline_products.csv')
            ->where('connection.priceMode', 'add_tax')
            ->where('shopTax.pricesIncludeTax', false)
            ->where('shopTax.taxRate', '2.5')
            ->where('shopTax.naldaTaxRate', '2.6')
            ->has('priceModes', 2));
});

test('a member cannot change the nalda settings', function () {
    [$user, $shop] = naldaMember(ShopRole::Member);

    $this->actingAs($user)
        ->patch(route('shops.nalda.update', $shop), naldaSettings())
        ->assertForbidden();
});

test('testing the connection uses the stored password when none is typed', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $connection = NaldaConnection::factory()->for($shop)->create(['sftp_password' => 'stored']);

    $this->mock(NaldaSftp::class)
        ->shouldReceive('check')
        ->once()
        ->withArgs(fn (NaldaConnection $tested) => $tested->sftp_password === 'stored');

    $this->actingAs($user)
        ->post(route('shops.nalda.test', $shop), ['sftp_username' => $connection->sftp_username])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Connected to the Nalda SFTP server.']);

    expect($connection->refresh()->verified_at)->not->toBeNull();
});

test('a refused login is reported', function () {
    $user = User::factory()->create();

    $this->mock(NaldaSftp::class)
        ->shouldReceive('check')
        ->andThrow(NaldaUploadException::authentication());

    $this->actingAs($user)
        ->post(route('shops.nalda.test', $user->currentShop), ['sftp_username' => 'someone', 'sftp_password' => 'wrong'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Nalda rejected the SFTP username or password.']);
});

test('upload now queues an export', function () {
    Bus::fake();

    $user = User::factory()->create();
    $shop = $user->currentShop;
    NaldaConnection::factory()->for($shop)->create();

    $this->actingAs($user)
        ->post(route('shops.nalda.exports.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Upload to Nalda queued.']);

    Bus::assertDispatched(UploadNaldaProductFeed::class);

    $this->post(route('shops.nalda.exports.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'An upload to Nalda is already in progress.']);

    expect($shop->naldaExports()->count())->toBe(1);
});

test('upload now needs credentials', function () {
    Bus::fake();

    $user = User::factory()->create();
    $shop = $user->currentShop;
    NaldaConnection::factory()->for($shop)->withoutCredentials()->create();

    $this->actingAs($user)
        ->post(route('shops.nalda.exports.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Save the Nalda SFTP user and password before uploading.']);

    Bus::assertNothingDispatched();
});

test('a member can download the product file', function () {
    [$user, $shop] = naldaMember(ShopRole::Member);
    NaldaConnection::factory()->for($shop)->create();
    Product::factory()->for($shop)->create(['gtin' => '4002725988737', 'name' => 'Canvas Tote']);

    $response = $this->actingAs($user)
        ->get(route('shops.nalda.download', $shop))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toStartWith('gtin,title,country')
        ->and($csv)->toContain('4002725988737,"Canvas Tote",CH');
});

test('another shop\'s nalda page is not reachable', function () {
    $user = User::factory()->create();
    $otherShop = Shop::factory()->create();

    $this->actingAs($user)
        ->get(route('shops.nalda.edit', $otherShop))
        ->assertForbidden();
});

test('a product can be set to always include or exclude', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();

    expect($product->refresh()->nalda_listing)->toBe(NaldaListing::Default);

    $this->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
            'status' => $product->status->value,
            'nalda_listing' => 'exclude',
        ])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->nalda_listing)->toBe(NaldaListing::Exclude);
});

test('the listing can be set on several products at once', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $products = Product::factory()->for($shop)->count(2)->create();

    $this->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'set-nalda-listing',
            'ids' => $products->pluck('id')->all(),
            'nalda_listing' => 'include',
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 products updated for Nalda.']);

    expect(Product::query()->where('nalda_listing', 'include')->count())->toBe(2);
});
