<?php

use App\Actions\Sync\CalculateNextSyncRun;
use App\Enums\SyncFrequency;
use App\Models\ShopSyncSetting;

/**
 * @param  array<string, mixed>  $overrides
 */
function syncSettings(array $overrides = []): ShopSyncSetting
{
    return ShopSyncSetting::factory()->make([
        'is_enabled' => true,
        ...$overrides,
    ]);
}

function nextRun(ShopSyncSetting $settings): ?string
{
    return app(CalculateNextSyncRun::class)->handle($settings)?->toDateTimeString();
}

test('a disabled schedule never runs', function () {
    expect(nextRun(syncSettings(['is_enabled' => false])))->toBeNull();
});

test('an hourly schedule runs at the next matching minute', function () {
    $this->travelTo('2026-09-11 10:20:00');

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::Hourly,
        'time_of_day' => '03:30',
    ])))->toBe('2026-09-11 10:30:00');
});

test('an hourly schedule rolls into the next hour once the minute has passed', function () {
    $this->travelTo('2026-09-11 10:40:00');

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::Hourly,
        'time_of_day' => '03:30',
    ])))->toBe('2026-09-11 11:30:00');
});

test('a six hourly schedule picks the next anchored slot', function () {
    $this->travelTo('2026-09-11 10:00:00');

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::SixHourly,
        'time_of_day' => '02:15',
    ])))->toBe('2026-09-11 14:15:00');
});

test('a six hourly schedule rolls to the next day after the last slot', function () {
    $this->travelTo('2026-09-11 23:00:00');

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::SixHourly,
        'time_of_day' => '02:15',
    ])))->toBe('2026-09-12 02:15:00');
});

test('a daily schedule runs later today when the time is still ahead', function () {
    $this->travelTo('2026-09-11 01:00:00');

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::Daily,
        'time_of_day' => '02:00',
    ])))->toBe('2026-09-11 02:00:00');
});

test('a daily schedule rolls to tomorrow once the time has passed', function () {
    $this->travelTo('2026-09-11 03:00:00');

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::Daily,
        'time_of_day' => '02:00',
    ])))->toBe('2026-09-12 02:00:00');
});

test('a weekly schedule lands on the configured weekday', function () {
    $this->travelTo('2026-09-11 03:00:00'); // A Friday.

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::Weekly,
        'time_of_day' => '09:00',
        'day_of_week' => 1, // Monday.
    ])))->toBe('2026-09-14 09:00:00');
});

test('a weekly schedule can still run later on the configured day', function () {
    $this->travelTo('2026-09-14 08:00:00'); // The Monday itself.

    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::Weekly,
        'time_of_day' => '09:00',
        'day_of_week' => 1,
    ])))->toBe('2026-09-14 09:00:00');
});

test('the time of day is interpreted in the shop timezone and stored as utc', function () {
    $this->travelTo('2026-09-11 00:00:00');

    // 02:00 in Dhaka (UTC+6) is 20:00 UTC the previous day.
    expect(nextRun(syncSettings([
        'frequency' => SyncFrequency::Daily,
        'time_of_day' => '02:00',
        'timezone' => 'Asia/Dhaka',
    ])))->toBe('2026-09-11 20:00:00');
});
