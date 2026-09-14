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

test('the tax rate is validated', function (mixed $rate) {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->patch(route('shops.update', $shop), ['name' => $shop->name, 'tax_rate' => $rate])
        ->assertSessionHasErrors('tax_rate');
})->with(['-1', '101', '8.123', 'eight', '']);
