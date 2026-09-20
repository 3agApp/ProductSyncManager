<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Every page a signed in user can reach should render.
 *
 * This is the cheapest guard against the failure that leaves nothing on the
 * screen at all, which no amount of server side testing can see.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    Product::factory()->count(3)->for($this->shop)->branded()->categorized()->create();

    $this->actingAs($this->user);
});

it('renders the page', function (string $path, string $heading) {
    visit(str_replace('{shop}', $this->shop->slug, $path))
        ->assertSee($heading)
        ->assertNoJavaScriptErrors();
})->with([
    'dashboard' => ['/{shop}/dashboard', 'Dashboard'],
    'products' => ['/{shop}/products', 'Products'],
    'new product' => ['/{shop}/products/create', 'Add product'],
    'categories' => ['/{shop}/categories', 'Categories'],
    'brands' => ['/{shop}/brands', 'Brands'],
    'profile settings' => ['/settings/profile', 'Profile'],
    'shop list' => ['/settings/shops', 'Shops'],
    'appearance settings' => ['/settings/appearance', 'Appearance settings'],
    'shop settings' => ['/settings/shops/{shop}', 'Shop settings'],
    'woocommerce connection' => ['/settings/shops/{shop}/sync', 'WooCommerce connection'],
    'nalda marketplace' => ['/settings/shops/{shop}/nalda', 'Nalda marketplace'],
]);

it('opens the detail dialog for a product', function () {
    $product = Product::factory()->for($this->shop)->branded()->create(['name' => 'Smoke Test Product']);

    visit("/{$this->shop->slug}/products?search=Smoke+Test+Product")
        ->click('@product-detail-open')
        ->assertSee($product->sku)
        ->assertNoJavaScriptErrors();
});

it('opens the edit page for a product from the row menu', function () {
    Product::factory()->for($this->shop)->branded()->create(['name' => 'Smoke Test Product']);

    visit("/{$this->shop->slug}/products?search=Smoke+Test+Product")
        ->click('@product-actions')
        ->click('@product-edit')
        ->assertSee('Edit product')
        ->assertNoJavaScriptErrors();
});

it('holds an unverified user at the verification prompt until they verify', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    visit(route('dashboard', $user->currentShop))
        ->assertPathIs('/email/verify')
        ->assertSee('Resend verification email')
        ->assertNoJavaScriptErrors();

    // The link the verification mail carries.
    visit(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]))
        ->assertPathIs("/{$user->currentShop->slug}/dashboard")
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
