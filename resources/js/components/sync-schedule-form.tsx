import { Form } from '@inertiajs/react';
import { Clock3 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { update as updateSchedule } from '@/routes/shops/sync/schedule';
import type { SyncFrequency, SyncFrequencyOption, SyncSettings } from '@/types';

const DAYS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];
const MINUTES = Array.from({ length: 60 }, (_, minute) =>
    String(minute).padStart(2, '0'),
);

function clockTime(time: string): string {
    const [hour, minute] = time.split(':').map(Number);
    return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
}

function scheduleDescription(
    frequency: SyncFrequency,
    time: string,
    day: string,
): string {
    if (!time) return 'Choose a time to preview your schedule.';
    const [hour, minute] = time.split(':');
    if (frequency === 'hourly') {
        return minute === '00'
            ? 'Every hour, on the hour.'
            : `Every hour at ${minute} minutes past the hour.`;
    }
    if (frequency === 'six_hourly') {
        const anchor = Number(hour) % 6;
        const times = [0, 6, 12, 18].map((offset) =>
            clockTime(`${anchor + offset}:${minute}`),
        );
        return `Every day at ${times.join(', ')}.`;
    }
    return frequency === 'weekly'
        ? `Every ${DAYS[Number(day)]} at ${clockTime(time)}.`
        : `Every day at ${clockTime(time)}.`;
}

export default function SyncScheduleForm({
    shopSlug,
    settings,
    frequencies,
    timezones,
}: {
    shopSlug: string;
    settings: SyncSettings;
    frequencies: SyncFrequencyOption[];
    timezones: string[];
}) {
    const [localZone] = useState(
        () => Intl.DateTimeFormat().resolvedOptions().timeZone,
    );
    const defaultZone =
        !settings.isConfigured && localZone && timezones.includes(localZone)
            ? localZone
            : settings.timezone;
    const [zoneOverride, setZoneOverride] = useState<string | null>(null);
    const timezone = zoneOverride ?? defaultZone;
    const [changeTimezone, setChangeTimezone] = useState(false);
    const [frequency, setFrequency] = useState(settings.frequency);
    const [enabled, setEnabled] = useState(settings.isEnabled);
    const [time, setTime] = useState(settings.timeOfDay);
    const [minute, setMinute] = useState(settings.timeOfDay.split(':')[1]);
    const [day, setDay] = useState(String(settings.dayOfWeek ?? 1));
    const submittedTime = frequency === 'hourly' ? `00:${minute}` : time;
    const savedTime =
        settings.frequency === 'hourly'
            ? `00:${settings.timeOfDay.split(':')[1]}`
            : settings.timeOfDay;
    const hasChanges =
        !settings.isConfigured ||
        enabled !== settings.isEnabled ||
        frequency !== settings.frequency ||
        submittedTime !== savedTime ||
        timezone !== settings.timezone ||
        (frequency === 'weekly' && Number(day) !== settings.dayOfWeek);

    return (
        <Form
            {...updateSchedule.form(shopSlug)}
            options={{ preserveScroll: true }}
            className="space-y-5"
            data-test="schedule-form"
        >
            {({ errors, processing }) => (
                <>
                    <div className="flex items-start gap-3 rounded-lg border p-4">
                        <Checkbox
                            id="is_enabled"
                            name="is_enabled"
                            value="1"
                            checked={enabled}
                            onCheckedChange={(value) =>
                                setEnabled(value === true)
                            }
                            data-test="schedule-enabled"
                            className="mt-0.5"
                        />
                        <div className="space-y-1">
                            <Label htmlFor="is_enabled">
                                Automatically sync the full catalog
                            </Label>
                            <p className="text-muted-foreground text-sm">
                                Catch changes that webhooks may have missed.
                            </p>
                        </div>
                    </div>
                    <InputError message={errors.is_enabled} />
                    <div className="grid gap-2">
                        <Label htmlFor="frequency">How often?</Label>
                        <Select
                            name="frequency"
                            value={frequency}
                            onValueChange={(value) =>
                                setFrequency(value as SyncFrequency)
                            }
                        >
                            <SelectTrigger
                                id="frequency"
                                data-test="schedule-frequency"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {frequencies.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.frequency} />
                    </div>
                    <div
                        className={
                            frequency === 'weekly'
                                ? 'grid gap-4 sm:grid-cols-2'
                                : 'grid gap-4'
                        }
                    >
                        {frequency === 'weekly' && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="day_of_week">
                                    On which day?
                                </Label>
                                <Select
                                    name="day_of_week"
                                    value={day}
                                    onValueChange={setDay}
                                >
                                    <SelectTrigger
                                        id="day_of_week"
                                        data-test="schedule-day"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {DAYS.map((label, index) => (
                                            <SelectItem
                                                key={label}
                                                value={String(index)}
                                            >
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.day_of_week} />
                            </div>
                        )}
                        <div className="grid content-start gap-2">
                            <input
                                type="hidden"
                                name="time_of_day"
                                value={submittedTime}
                            />
                            {frequency === 'hourly' ? (
                                <>
                                    <Label htmlFor="schedule-minute">
                                        When during each hour?
                                    </Label>
                                    <Select
                                        value={minute}
                                        onValueChange={setMinute}
                                    >
                                        <SelectTrigger
                                            id="schedule-minute"
                                            data-test="schedule-minute"
                                            className="w-full"
                                            aria-describedby="schedule-time-help"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {MINUTES.map((value) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {value === '00'
                                                        ? 'On the hour (:00)'
                                                        : `${Number(value)} minutes past (:${value})`}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p
                                        id="schedule-time-help"
                                        className="text-muted-foreground text-sm"
                                    >
                                        For example, :15 runs at 9:15, 10:15,
                                        11:15, and so on.
                                    </p>
                                </>
                            ) : (
                                <>
                                    <Label htmlFor="time_of_day">
                                        {frequency === 'six_hourly'
                                            ? 'Choose a run time'
                                            : 'Run at'}
                                    </Label>
                                    <Input
                                        id="time_of_day"
                                        type="time"
                                        className="scheme-light dark:scheme-dark"
                                        data-test="schedule-time"
                                        value={time}
                                        onChange={(event) =>
                                            setTime(event.target.value)
                                        }
                                        required
                                        aria-describedby="schedule-time-help"
                                    />
                                    <p
                                        id="schedule-time-help"
                                        className="text-muted-foreground text-sm"
                                    >
                                        {frequency === 'six_hourly'
                                            ? 'Also runs every 6 hours before and after this time. See all four times below.'
                                            : 'Choose a quiet time for your store, such as overnight.'}
                                    </p>
                                </>
                            )}
                            <InputError message={errors.time_of_day} />
                        </div>
                    </div>
                    <input type="hidden" name="timezone" value={timezone} />
                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <p className="text-muted-foreground min-w-0 text-sm break-words">
                                Times are in{' '}
                                <span className="text-foreground font-medium">
                                    {timezone.replaceAll('_', ' ')}
                                </span>
                                {timezone === localZone
                                    ? ' (your timezone).'
                                    : '.'}
                            </p>
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0 text-sm"
                                onClick={() =>
                                    setChangeTimezone(!changeTimezone)
                                }
                                aria-expanded={changeTimezone}
                                aria-controls="schedule-timezone-picker"
                            >
                                {changeTimezone ? 'Done' : 'Change timezone'}
                            </Button>
                        </div>
                        {changeTimezone && (
                            <div
                                id="schedule-timezone-picker"
                                className="grid gap-2"
                            >
                                <Label htmlFor="timezone">Timezone</Label>
                                <Select
                                    value={timezone}
                                    onValueChange={setZoneOverride}
                                >
                                    <SelectTrigger
                                        id="timezone"
                                        data-test="schedule-timezone"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {timezones.map((zone) => (
                                            <SelectItem key={zone} value={zone}>
                                                {zone.replaceAll('_', ' ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {localZone &&
                                    timezones.includes(localZone) &&
                                    timezone !== localZone && (
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="h-auto justify-self-start p-0 text-sm"
                                            onClick={() =>
                                                setZoneOverride(localZone)
                                            }
                                        >
                                            Use my timezone (
                                            {localZone.replaceAll('_', ' ')})
                                        </Button>
                                    )}
                                <p className="text-muted-foreground text-sm">
                                    The selected time follows this timezone,
                                    even if your server is elsewhere.
                                </p>
                            </div>
                        )}
                        <InputError message={errors.timezone} />
                    </div>
                    <div
                        className="bg-muted/40 flex items-start gap-3 rounded-lg border p-4"
                        role="status"
                        aria-live="polite"
                        data-test="schedule-preview"
                    >
                        <Clock3
                            className="text-muted-foreground mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <div className="min-w-0 space-y-1 text-sm">
                            <p className="font-medium">
                                {enabled
                                    ? 'Your schedule'
                                    : settings.isEnabled
                                      ? 'Scheduled sync will be paused'
                                      : 'Scheduled sync is off'}
                            </p>
                            <p>
                                {scheduleDescription(
                                    frequency,
                                    submittedTime,
                                    day,
                                )}
                            </p>
                            <p className="text-muted-foreground break-words">
                                {enabled
                                    ? `Times shown in ${timezone.replaceAll('_', ' ')}.`
                                    : settings.isEnabled
                                      ? 'Save to pause scheduled sync. Webhooks and manual sync will keep working.'
                                      : 'Enable automatic sync above to use this schedule. Webhooks and manual sync still work.'}
                            </p>
                            {hasChanges && (
                                <p className="text-muted-foreground">
                                    Save to apply your changes.
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <Button
                            type="submit"
                            data-test="schedule-save"
                            disabled={processing}
                        >
                            {processing && <Spinner />}Save schedule
                        </Button>
                        {!hasChanges &&
                            settings.isEnabled &&
                            settings.nextRunAtDiff && (
                                <p
                                    className="text-muted-foreground text-sm"
                                    data-test="schedule-next-run"
                                >
                                    Next run {settings.nextRunAtDiff}.
                                </p>
                            )}
                    </div>
                </>
            )}
        </Form>
    );
}
