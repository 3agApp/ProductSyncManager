<?php

use App\Models\Product;
use App\Models\User;

/**
 * The detail dialog has to hold a full gallery and text that never wraps
 * kindly without pushing the page sideways, at every width.
 *
 * The data is created here rather than mocked into the response, so the
 * dialog is laid out from the same shape the application really serves.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->product = Product::factory()->for($this->shop)
        ->branded('LongBrand'.str_repeat('Name', 25))
        ->categorized('Parent > '.str_repeat('LongCategory', 25))
        ->create([
            'name' => 'Gallery regression '.str_repeat('LongName', 25),
            'sku' => str_repeat('SKU', 20),
            'description' => 'A long reference: https://example.test/'.str_repeat('reference', 100),
            'image_url' => 'https://preview-images.test/0.svg',
            'gallery_image_urls' => array_map(
                fn (int $i) => "https://preview-images.test/{$i}.svg",
                range(1, 9),
            ),
        ]);

    $this->actingAs($this->user);
});

it('shows the whole gallery in the detail dialog', function () {
    visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->click('@product-detail-open')
        ->assertCount('@product-detail-image', 10)
        ->assertNoJavaScriptErrors();
});

it('keeps the detail dialog inside the viewport at every width', function (int $width) {
    $page = visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->resize($width, 800)
        ->click('@product-detail-open')
        ->assertPresent('@product-detail-gallery');

    $overflows = $page->script(
        'document.documentElement.scrollWidth > window.innerWidth'
    );

    expect($overflows)->toBeFalse("the page scrolls sideways at {$width}px");

    $page->assertNoJavaScriptErrors();
})->with([1440, 390, 320]);
