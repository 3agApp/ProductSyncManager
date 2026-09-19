<?php

use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->actingAs($this->user);
});

/**
 * Pick an option from one of the schedule selects.
 *
 * The schedule sits well down a long settings page, and the dropdown opens
 * where the trigger is, so the trigger has to be on screen before it is
 * opened or the options land outside the viewport.
 */
function chooseSchedule(AwaitableWebpage $page, string $trigger, string $option): AwaitableWebpage
{
    $page->script("document.querySelector('[data-test=schedule-form]').scrollIntoView({block: 'center'})");

    return $page->click($trigger)->click('[role="option"]:has-text("'.$option.'")');
}

it('explains what each frequency will do before anything is saved', function () {
    $page = visit(route('shops.sync.edit', $this->shop))
        ->withTimezone('Asia/Dhaka')
        ->assertSeeIn('@schedule-form', 'Asia/Dhaka (your timezone)')
        ->assertSeeIn('@schedule-preview', 'Scheduled sync is off')
        ->check('@schedule-enabled')
        ->assertSeeIn('@schedule-preview', 'Every day at 2:00 AM.');

    chooseSchedule($page, '@schedule-frequency', 'Hourly')
        ->assertMissing('@schedule-time');

    chooseSchedule($page, '@schedule-minute', '15 minutes past (:15)')
        ->assertSeeIn('@schedule-preview', 'Every hour at 15 minutes past the hour.');

    chooseSchedule($page, '@schedule-frequency', 'Every 6 hours')
        ->fill('@schedule-time', '14:30')
        ->assertSeeIn('@schedule-preview', 'Every day at 2:30 AM, 8:30 AM, 2:30 PM, 8:30 PM.');

    chooseSchedule($page, '@schedule-frequency', 'Weekly');
    chooseSchedule($page, '@schedule-day', 'Friday')
        ->fill('@schedule-time', '03:45')
        ->assertSeeIn('@schedule-preview', 'Every Friday at 3:45 AM.')
        ->assertNoJavaScriptErrors();
});

it('keeps the minute of the hour after saving and reloading', function () {
    $page = visit(route('shops.sync.edit', $this->shop))
        ->withTimezone('Asia/Dhaka')
        ->check('@schedule-enabled');

    chooseSchedule($page, '@schedule-frequency', 'Hourly');
    chooseSchedule($page, '@schedule-minute', '15 minutes past (:15)')
        ->click('@schedule-save')
        ->assertPresent('@schedule-next-run')
        ->assertNoJavaScriptErrors();

    visit(route('shops.sync.edit', $this->shop))
        ->withTimezone('Asia/Dhaka')
        ->assertSeeIn('@schedule-minute', '15 minutes past (:15)')
        ->assertNoJavaScriptErrors();
});

it('keeps a timezone chosen by hand instead of the browser one', function () {
    $page = visit(route('shops.sync.edit', $this->shop))
        ->withTimezone('Asia/Dhaka')
        ->check('@schedule-enabled');

    $page->script("document.querySelector('[data-test=schedule-form]').scrollIntoView({block: 'center'})");
    $page->click('button:has-text("Change timezone")');

    chooseSchedule($page, '@schedule-timezone', 'Europe/London')
        ->assertSeeIn('@schedule-preview', 'Times shown in Europe/London.')
        ->click('@schedule-save')
        ->assertNoJavaScriptErrors();

    visit(route('shops.sync.edit', $this->shop))
        ->withTimezone('Asia/Dhaka')
        ->assertSeeIn('@schedule-preview', 'Times shown in Europe/London.')
        ->assertNoJavaScriptErrors();
});

it('turns the schedule off again', function () {
    visit(route('shops.sync.edit', $this->shop))
        ->check('@schedule-enabled')
        ->click('@schedule-save')
        ->assertPresent('@schedule-next-run')
        ->uncheck('@schedule-enabled')
        ->assertSeeIn('@schedule-preview', 'Scheduled sync will be paused')
        ->click('@schedule-save')
        ->assertNoJavaScriptErrors();

    visit(route('shops.sync.edit', $this->shop))
        ->assertSeeIn('@schedule-preview', 'Scheduled sync is off')
        ->assertMissing('@schedule-next-run')
        ->assertNoJavaScriptErrors();
});

it('fits the schedule form on desktop and mobile without scrolling sideways', function (int $width) {
    $page = visit(route('shops.sync.edit', $this->shop))
        ->resize($width, 1000)
        ->check('@schedule-enabled');

    expect($page->script('document.documentElement.scrollWidth > window.innerWidth'))
        ->toBeFalse("the page scrolls sideways at {$width}px");

    $page->assertNoJavaScriptErrors();
})->with([1280, 390]);
