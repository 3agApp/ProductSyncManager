import { Form, Head, router, usePoll } from '@inertiajs/react';
import { PlugZap, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import SyncProgress from '@/components/sync-progress';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { edit as editShop, index as shopsIndex } from '@/routes/shops';
import { edit as editSync, test as testConnection } from '@/routes/shops/sync';
import { update as updateConnection } from '@/routes/shops/sync/connection';
import { store as startSyncRun } from '@/routes/shops/sync/runs';
import { update as updateSchedule } from '@/routes/shops/sync/schedule';
import type {
    Shop,
    ShopPermissions,
    SyncFrequencyOption,
    SyncRun,
    SyncRunStatus,
    SyncSettings,
} from '@/types';

type Props = {
    shop: Shop;
    supportsSync: boolean;
    connection: {
        storeUrl: string;
        consumerKeyPreview: string;
        consumerSecretPreview: string;
        verifiedAt: string | null;
        isUsable: boolean;
    } | null;
    settings: SyncSettings;
    currentRun: SyncRun | null;
    recentRuns: SyncRun[];
    frequencies: SyncFrequencyOption[];
    timezones: string[];
    permissions: ShopPermissions;
};

const DAYS = [
    { value: '0', label: 'Sunday' },
    { value: '1', label: 'Monday' },
    { value: '2', label: 'Tuesday' },
    { value: '3', label: 'Wednesday' },
    { value: '4', label: 'Thursday' },
    { value: '5', label: 'Friday' },
    { value: '6', label: 'Saturday' },
];

const STATUS_VARIANTS: Record<SyncRunStatus, string> = {
    queued: 'text-muted-foreground',
    running: 'text-blue-600 dark:text-blue-400',
    completed: 'text-emerald-600 dark:text-emerald-400',
    failed: 'text-red-600 dark:text-red-400',
};

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

export default function ShopSync({
    shop,
    supportsSync,
    connection,
    settings,
    currentRun,
    recentRuns,
    frequencies,
    timezones,
    permissions,
}: Props) {
    const [frequency, setFrequency] = useState(settings.frequency);
    const [testing, setTesting] = useState(false);
    const [starting, setStarting] = useState(false);

    const isActive = currentRun?.isActive ?? false;

    // A finished job cannot flash a toast from the server, because it runs
    // outside any request. Watch the polled status change instead.
    const previousStatus = useRef<SyncRunStatus | null>(
        currentRun?.status ?? null,
    );

    useEffect(() => {
        const previous = previousStatus.current;
        const current = currentRun?.status ?? null;

        if (previous && current && previous !== current) {
            if (current === 'completed') {
                toast.success('Sync finished.');
            } else if (current === 'failed') {
                toast.error(currentRun?.message ?? 'The sync failed.');
            }
        }

        previousStatus.current = current;
    }, [currentRun?.status, currentRun?.message]);

    const { start, stop } = usePoll(
        3000,
        { only: ['currentRun', 'recentRuns'] },
        { autoStart: isActive, keepAlive: false, mode: 'cancel' },
    );

    useEffect(() => {
        if (isActive) {
            start();
        } else {
            stop();
        }
    }, [isActive, start, stop]);

    const runTest = () => {
        const form = document.querySelector<HTMLFormElement>(
            '[data-test="connection-form"]',
        );
        const data = form ? Object.fromEntries(new FormData(form)) : {};

        router.visit(testConnection(shop.slug), {
            data,
            preserveScroll: true,
            onStart: () => setTesting(true),
            onFinish: () => setTesting(false),
        });
    };

    const syncNow = () => {
        router.visit(startSyncRun(shop.slug), {
            preserveScroll: true,
            onStart: () => setStarting(true),
            onFinish: () => setStarting(false),
        });
    };

    if (!supportsSync) {
        return (
            <>
                <Head title={`Sync ${shop.name}`} />

                <Heading
                    variant="small"
                    title="Product sync"
                    description={`${shop.typeLabel ?? 'This'} shops cannot sync products.`}
                />
            </>
        );
    }

    return (
        <>
            <Head title={`Sync ${shop.name}`} />

            <div className="flex flex-col space-y-10">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="WooCommerce connection"
                        description="Point this shop at your WooCommerce store. Generate a read-only REST API key in WooCommerce under Settings, Advanced, REST API."
                    />

                    {permissions.canManageSync ? (
                        <Form
                            {...updateConnection.form(shop.slug)}
                            data-test="connection-form"
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="store_url">
                                            Store address
                                        </Label>
                                        <Input
                                            id="store_url"
                                            name="store_url"
                                            type="url"
                                            data-test="store-url-input"
                                            placeholder="https://example.com"
                                            defaultValue={
                                                connection?.storeUrl ?? ''
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.store_url}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="consumer_key">
                                            Consumer key
                                        </Label>
                                        <Input
                                            id="consumer_key"
                                            name="consumer_key"
                                            data-test="consumer-key-input"
                                            placeholder={
                                                connection?.consumerKeyPreview ??
                                                'ck_…'
                                            }
                                            required={!connection}
                                        />
                                        <InputError
                                            message={errors.consumer_key}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="consumer_secret">
                                            Consumer secret
                                        </Label>
                                        <Input
                                            id="consumer_secret"
                                            name="consumer_secret"
                                            type="password"
                                            autoComplete="off"
                                            data-test="consumer-secret-input"
                                            placeholder={
                                                connection?.consumerSecretPreview ??
                                                'cs_…'
                                            }
                                            required={!connection}
                                        />
                                        <InputError
                                            message={errors.consumer_secret}
                                        />
                                        {connection ? (
                                            <p className="text-muted-foreground text-xs">
                                                Leave the key and secret blank
                                                to keep the stored ones.
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="flex items-center gap-4">
                                        <Button
                                            type="submit"
                                            data-test="connection-save"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save connection
                                        </Button>

                                        <Button
                                            type="button"
                                            variant="secondary"
                                            data-test="connection-test"
                                            onClick={runTest}
                                            disabled={testing}
                                        >
                                            {testing ? (
                                                <Spinner />
                                            ) : (
                                                <PlugZap className="size-4" />
                                            )}
                                            Test connection
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {connection
                                ? `Connected to ${connection.storeUrl}.`
                                : 'This shop is not connected to a store yet.'}
                        </p>
                    )}
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Scheduled sync"
                        description="Pull the catalog automatically on a schedule."
                    />

                    {permissions.canManageSync ? (
                        <Form
                            {...updateSchedule.form(shop.slug)}
                            options={{ preserveScroll: true }}
                            className="space-y-6"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex items-center gap-3">
                                        <Checkbox
                                            id="is_enabled"
                                            name="is_enabled"
                                            value="1"
                                            data-test="schedule-enabled"
                                            defaultChecked={settings.isEnabled}
                                        />
                                        <Label htmlFor="is_enabled">
                                            Sync on a schedule
                                        </Label>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="frequency">
                                            Frequency
                                        </Label>
                                        <Select
                                            name="frequency"
                                            value={frequency}
                                            onValueChange={(value) =>
                                                setFrequency(
                                                    value as typeof frequency,
                                                )
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
                                        <InputError
                                            message={errors.frequency}
                                        />
                                    </div>

                                    {frequency === 'weekly' ? (
                                        <div className="grid gap-2">
                                            <Label htmlFor="day_of_week">
                                                Day of the week
                                            </Label>
                                            <Select
                                                name="day_of_week"
                                                defaultValue={String(
                                                    settings.dayOfWeek ?? 1,
                                                )}
                                            >
                                                <SelectTrigger
                                                    id="day_of_week"
                                                    data-test="schedule-day"
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {DAYS.map((day) => (
                                                        <SelectItem
                                                            key={day.value}
                                                            value={day.value}
                                                        >
                                                            {day.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.day_of_week}
                                            />
                                        </div>
                                    ) : null}

                                    <div className="grid gap-2">
                                        <Label htmlFor="time_of_day">
                                            {frequency === 'hourly'
                                                ? 'Minutes past the hour'
                                                : 'Time of day'}
                                        </Label>
                                        <Input
                                            id="time_of_day"
                                            name="time_of_day"
                                            type="time"
                                            data-test="schedule-time"
                                            defaultValue={settings.timeOfDay}
                                            required
                                        />
                                        <InputError
                                            message={errors.time_of_day}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="timezone">
                                            Timezone
                                        </Label>
                                        <Select
                                            name="timezone"
                                            defaultValue={settings.timezone}
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
                                                    <SelectItem
                                                        key={zone}
                                                        value={zone}
                                                    >
                                                        {zone}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.timezone} />
                                    </div>

                                    <div className="flex items-center gap-4">
                                        <Button
                                            type="submit"
                                            data-test="schedule-save"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save schedule
                                        </Button>

                                        {settings.isEnabled &&
                                        settings.nextRunAtDiff ? (
                                            <p
                                                className="text-muted-foreground text-sm"
                                                data-test="schedule-next-run"
                                            >
                                                Next run{' '}
                                                {settings.nextRunAtDiff}.
                                            </p>
                                        ) : null}
                                    </div>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {settings.isEnabled
                                ? `Scheduled to run ${settings.nextRunAtDiff ?? 'soon'}.`
                                : 'Scheduled syncing is off.'}
                        </p>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <Heading
                            variant="small"
                            title="Sync now"
                            description="Pull the catalog immediately. Products are matched on their WooCommerce id, and anything the store no longer has is archived rather than deleted."
                        />

                        {permissions.canRunSync ? (
                            <Button
                                onClick={syncNow}
                                data-test="sync-now"
                                disabled={starting || isActive || !connection}
                            >
                                {starting || isActive ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw className="size-4" />
                                )}
                                {isActive ? 'Syncing…' : 'Sync now'}
                            </Button>
                        ) : null}
                    </div>

                    {currentRun && isActive ? (
                        <SyncProgress run={currentRun} />
                    ) : null}

                    {currentRun && !isActive && currentRun.message ? (
                        <Alert variant="destructive" data-test="sync-error">
                            <AlertTitle>
                                Last sync {currentRun.statusLabel.toLowerCase()}
                            </AlertTitle>
                            <AlertDescription>
                                {currentRun.message}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {!connection ? (
                        <p className="text-muted-foreground text-sm">
                            Save a WooCommerce connection before syncing.
                        </p>
                    ) : null}
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Recent syncs"
                        description="The last ten runs for this shop."
                    />

                    {recentRuns.length > 0 ? (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Started</TableHead>
                                        <TableHead className="text-right">
                                            Created
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Updated
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Archived
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Skipped
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Duration
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recentRuns.map((run) => (
                                        <TableRow
                                            key={run.id}
                                            data-test="sync-run-row"
                                        >
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        STATUS_VARIANTS[
                                                            run.status
                                                        ]
                                                    }
                                                >
                                                    {run.statusLabel}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {run.createdAtDiff} ·{' '}
                                                {run.triggerLabel}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {run.createdProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {run.updatedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {run.archivedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {run.skippedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatDuration(
                                                    run.durationInSeconds,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            This shop has not synced yet.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

ShopSync.layout = (props: { shop: { name: string; slug: string } }) => ({
    breadcrumbs: [
        { title: 'Shops', href: shopsIndex() },
        { title: props.shop.name, href: editShop(props.shop.slug) },
        { title: 'Sync', href: editSync(props.shop.slug) },
    ],
});
