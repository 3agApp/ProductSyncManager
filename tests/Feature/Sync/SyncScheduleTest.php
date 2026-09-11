<?php

use App\Enums\ShopRole;
use App\Enums\SyncFrequency;
use App\Models\Shop;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scheduleData(array $overrides = []): array
{
    return [
        'is_enabled' => true,
        'frequency' => SyncFrequency::Daily->value,
        'time_of_day' => '02:30',
        'timezone' => 'UTC',
        ...$overrides,
    ];
}

test('saving a schedule computes the next run', function () {
    $this->travelTo('2026-09-11 01:00:00');

    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->from(route('shops.sync.edit', $shop))
        ->patch(route('shops.sync.schedule.update', $shop), scheduleData())
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sync schedule saved.']);

    $settings = $shop->syncSettings()->sole();

    expect($settings->is_enabled)->toBeTrue()
        ->and($settings->next_run_at->toDateTimeString())->toBe('2026-09-11 02:30:00');
});

test('disabling the schedule clears the next run', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)->patch(route('shops.sync.schedule.update', $shop), scheduleData());

    expect($shop->syncSettings()->sole()->next_run_at)->not->toBeNull();

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.schedule.update', $shop), scheduleData(['is_enabled' => false]))
        ->assertSessionHasNoErrors();

    expect($shop->syncSettings()->sole()->next_run_at)->toBeNull();
});

test('a weekly schedule stores the chosen day', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.schedule.update', $shop), scheduleData([
            'frequency' => SyncFrequency::Weekly->value,
            'day_of_week' => 3,
        ]))
        ->assertSessionHasNoErrors();

    expect($shop->syncSettings()->sole()->day_of_week)->toBe(3);
});

test('a non weekly schedule does not keep a day of the week', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.schedule.update', $shop), scheduleData([
            'frequency' => SyncFrequency::Hourly->value,
            'day_of_week' => 3,
        ]))
        ->assertSessionHasNoErrors();

    expect($shop->syncSettings()->sole()->day_of_week)->toBeNull();
});

test('schedule data is validated', function (array $overrides, string $field) {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.schedule.update', $shop), scheduleData($overrides))
        ->assertSessionHasErrors($field);

    expect($shop->syncSettings()->count())->toBe(0);
})->with([
    'unknown frequency' => [['frequency' => 'fortnightly'], 'frequency'],
    'missing time' => [['time_of_day' => ''], 'time_of_day'],
    'malformed time' => [['time_of_day' => '25:99'], 'time_of_day'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'day out of range' => [['day_of_week' => 9], 'day_of_week'],
    'weekly without a day' => [
        ['frequency' => SyncFrequency::Weekly->value, 'day_of_week' => null],
        'day_of_week',
    ],
]);

test('members cannot change the schedule', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $this
        ->actingAs($member)
        ->patch(route('shops.sync.schedule.update', $shop), scheduleData())
        ->assertForbidden();
});
