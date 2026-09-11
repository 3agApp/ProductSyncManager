<?php

namespace App\Actions\Sync;

use App\Enums\SyncFrequency;
use App\Models\ShopSyncSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class CalculateNextSyncRun
{
    /**
     * The hours between runs for the six hourly frequency.
     */
    private const int SIX_HOURLY_STEP = 6;

    /**
     * Work out when a shop should next sync.
     *
     * The stored time of day is local to the shop's timezone, but the result
     * is always UTC so the dispatcher can compare it against now() directly.
     * A local time that does not exist on a spring forward day is shifted
     * forward by Carbon, which means such a run happens an hour late.
     */
    public function handle(ShopSyncSetting $settings, ?CarbonInterface $after = null): ?Carbon
    {
        if (! $settings->is_enabled) {
            return null;
        }

        $after = CarbonImmutable::instance($after ?? CarbonImmutable::now())
            ->setTimezone($settings->timezone);

        [$hour, $minute] = $this->timeOfDay($settings);

        $next = match ($settings->frequency) {
            SyncFrequency::Hourly => $this->hourly($after, $minute),
            SyncFrequency::SixHourly => $this->sixHourly($after, $hour, $minute),
            SyncFrequency::Daily => $this->daily($after, $hour, $minute),
            SyncFrequency::Weekly => $this->weekly($after, $hour, $minute, $settings->day_of_week ?? 0),
        };

        return Carbon::instance($next->setTimezone('UTC'));
    }

    /**
     * Get the next occurrence of the given minute past the hour.
     */
    private function hourly(CarbonImmutable $after, int $minute): CarbonImmutable
    {
        $candidate = $after->setTime($after->hour, $minute);

        return $candidate->isAfter($after) ? $candidate : $candidate->addHour();
    }

    /**
     * Get the next six hourly slot, anchored on the configured hour.
     */
    private function sixHourly(CarbonImmutable $after, int $hour, int $minute): CarbonImmutable
    {
        $anchor = $hour % self::SIX_HOURLY_STEP;

        for ($slot = $anchor; $slot < 24; $slot += self::SIX_HOURLY_STEP) {
            $candidate = $after->setTime($slot, $minute);

            if ($candidate->isAfter($after)) {
                return $candidate;
            }
        }

        return $after->addDay()->setTime($anchor, $minute);
    }

    /**
     * Get today's slot if it is still ahead, otherwise tomorrow's.
     */
    private function daily(CarbonImmutable $after, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $after->setTime($hour, $minute);

        return $candidate->isAfter($after) ? $candidate : $candidate->addDay();
    }

    /**
     * Get the next occurrence of the configured weekday and time.
     */
    private function weekly(CarbonImmutable $after, int $hour, int $minute, int $dayOfWeek): CarbonImmutable
    {
        $candidate = $after->setTime($hour, $minute);

        while ($candidate->dayOfWeek !== $dayOfWeek || ! $candidate->isAfter($after)) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * Parse the stored HH:MM time of day.
     *
     * @return array{0: int, 1: int}
     */
    private function timeOfDay(ShopSyncSetting $settings): array
    {
        $parts = explode(':', $settings->time_of_day);

        return [
            max(0, min(23, (int) $parts[0])),
            max(0, min(59, (int) ($parts[1] ?? 0))),
        ];
    }
}
