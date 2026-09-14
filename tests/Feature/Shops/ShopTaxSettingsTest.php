<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a shop starts with prices including the swiss standard rate', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop->refresh();

    expect($shop->prices_include_tax)->toBeTrue()
        ->and($shop->tax_rate)->toBe('8.10');

    $this->actingAs($user)
        ->get(route('shops.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shop.pricesIncludeTax', true)
            ->where('shop.taxRate', '8.1'));
});

test('an owner can change the tax settings', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->patch(route('shops.update', $shop), [
            'name' => $shop->name,
            'prices_include_tax' => '0',
            'tax_rate' => '2.6',
        ])
        ->assertSessionHasNoErrors();

    $shop->refresh();

    expect($shop->prices_include_tax)->toBeFalse()
        ->and($shop->tax_rate)->toBe('2.60');
});

test('renaming a shop leaves its tax settings alone', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $shop->update(['prices_include_tax' => false, 'tax_rate' => '2.6']);

    $this->actingAs($user)
        ->patch(route('shops.update', $shop), ['name' => 'Renamed Shop'])
        ->assertSessionHasNoErrors();

    $shop->refresh();

    expect($shop->prices_include_tax)->toBeFalse()
        ->and($shop->tax_rate)->toBe('2.60');
});

test('a shop starts in swiss francs and shares its currency with every page', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    expect($shop->refresh()->currency)->toBe('CHF');

    $this->actingAs($user)
        ->get(route('shops.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shop.currency', 'CHF')
            ->where('currentShop.currency', 'CHF'));
});

test('the product form prices in the shop\'s currency', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $shop->update(['currency' => 'EUR']);

    $this->actingAs($user)
        ->get(route('products.create', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shop.currency', 'EUR')
            ->where('currentShop.currency', 'EUR'));
});

test('an owner can change the currency', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->patch(route('shops.update', $shop), ['name' => $shop->name, 'currency' => ' eur '])
        ->assertSessionHasNoErrors();

    expect($shop->refresh()->currency)->toBe('EUR');
});

test('the currency is validated', function (string $currency) {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->patch(route('shops.update', $shop), ['name' => $shop->name, 'currency' => $currency])
        ->assertSessionHasErrors('currency');
})->with(['', 'CH', 'CHFR', '12$']);

test('the tax rate is validated', function (mixed $rate) {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->patch(route('shops.update', $shop), ['name' => $shop->name, 'tax_rate' => $rate])
        ->assertSessionHasErrors('tax_rate');
})->with(['-1', '101', '8.123', 'eight', '']);
