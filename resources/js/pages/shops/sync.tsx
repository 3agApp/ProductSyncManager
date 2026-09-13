import { Form, Head, router, usePoll } from '@inertiajs/react';
import { PlugZap, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import SyncProgress from '@/components/sync-progress';
import SyncScheduleForm from '@/components/sync-schedule-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { store as setupWebhooks } from '@/routes/shops/sync/webhooks';
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
        webhookUrl: string;
        webhooksConfiguredAt: string | null;
        webhookConfigured: boolean;
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
                <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                    <Heading
                        variant="small"
                        title="WooCommerce connection"
                        description="Point this shop at your WooCommerce store. Generate a REST API key in WooCommerce under Settings, Advanced, REST API."
                    />

                    {permissions.canManageSync ? (
                        <Form
                            {...updateConnection.form(shop.slug)}
                            data-test="connection-form"
                            resetOnSuccess={['consumer_key', 'consumer_secret']}
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

                                    <div className="flex flex-wrap items-center gap-4">
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

                <div
                    className="space-y-5 border-t pt-8"
                    data-test="automatic-webhooks"
                >
                    <Heading
                        variant="small"
                        title="Automatic webhook setup"
                        description="Connect product changes to this app without copying URLs or secrets into WooCommerce."
                    />
                    {permissions.canManageSync ? (
                        <Form
                            {...setupWebhooks.form(shop.slug)}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <p className="text-muted-foreground text-sm">
                                        Uses your saved API credentials to
                                        configure product created, updated,
                                        deleted, and restored webhooks. Existing
                                        matches are reused. Save any connection
                                        changes above before continuing.
                                    </p>
                                    {errors.webhooks && (
                                        <Alert
                                            variant="destructive"
                                            role="alert"
                                            data-test="webhook-setup-error"
                                        >
                                            <AlertTitle>
                                                Webhook setup could not finish
                                            </AlertTitle>
                                            <AlertDescription>
                                                {errors.webhooks}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    {connection?.webhooksConfiguredAt &&
                                        !errors.webhooks && (
                                            <p
                                                className="text-muted-foreground text-sm"
                                                role="status"
                                                data-test="webhook-setup-success"
                                            >
                                                All four product webhooks were
                                                configured successfully. Run
                                                setup again to check and repair
                                                them.
                                            </p>
                                        )}
                                    <Button
                                        type="submit"
                                        disabled={processing || !connection}
                                        data-test="webhook-setup-button"
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <PlugZap className="size-4" />
                                        )}
                                        {processing
                                            ? 'Setting up webhooks…'
                                            : connection?.webhooksConfiguredAt
                                              ? 'Check and repair webhooks'
                                              : 'Set up webhooks automatically'}
                                    </Button>
                                    {!connection && (
                                        <p className="text-muted-foreground text-sm">
                                            Save a WooCommerce connection above
                                            to get started.
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {connection?.webhooksConfiguredAt
                                ? 'Product webhooks have been configured.'
                                : 'An owner or admin can set up product webhooks.'}
                        </p>
                    )}
                    {permissions.canManageSync && connection && (
                        <details className="rounded-lg border p-4">
                            <summary className="cursor-pointer text-sm font-medium">
                                Manual webhook setup
                            </summary>
                            <Form
                                {...updateConnection.form(shop.slug)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess={['webhook_secret']}
                                className="mt-4 space-y-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="store_url"
                                            value={connection.storeUrl}
                                        />
                                        <div className="grid gap-2">
                                            <Label htmlFor="webhook_secret">
                                                Webhook secret (optional)
                                            </Label>
                                            <Input
                                                id="webhook_secret"
                                                name="webhook_secret"
                                                type="password"
                                                autoComplete="off"
                                                minLength={32}
                                                maxLength={255}
                                                placeholder={
                                                    connection?.webhookConfigured
                                                        ? 'Configured — leave blank to keep'
                                                        : 'Use a random secret of at least 32 characters'
                                                }
                                            />
                                            <InputError
                                                message={errors.webhook_secret}
                                            />
                                            <InputError
                                                message={errors.store_url}
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                Automatic setup generates a
                                                secret for you. For manual
                                                setup, save a secret here and
                                                use the same secret in
                                                WooCommerce. Changing the store
                                                address clears the saved webhook
                                                secret unless you enter a new
                                                one.
                                            </p>
                                            {connection && (
                                                <>
                                                    <Label htmlFor="webhook_url">
                                                        Webhook delivery URL
                                                    </Label>
                                                    <Input
                                                        id="webhook_url"
                                                        value={
                                                            connection.webhookUrl
                                                        }
                                                        readOnly
                                                        onFocus={(event) =>
                                                            event.currentTarget.select()
                                                        }
                                                    />
                                                    <p className="text-muted-foreground text-xs">
                                                        In WooCommerce →
                                                        Settings → Advanced →
                                                        Webhooks, create active
                                                        Product created, Product
                                                        updated, Product deleted
                                                        and Product restored
                                                        webhooks using this URL
                                                        and secret. Keep
                                                        scheduled sync enabled
                                                        to recover missed
                                                        changes. The URL must be
                                                        publicly reachable over
                                                        HTTPS.
                                                    </p>
                                                </>
                                            )}
                                        </div>

                                        <Button
                                            type="submit"
                                            variant="secondary"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}Save
                                            manual webhook settings
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </details>
                    )}
                </div>

                <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                    <Heading
                        variant="small"
                        title="Scheduled sync"
                        description="Check your full catalog regularly to keep products, prices, and stock up to date."
                    />

                    {permissions.canManageSync ? (
                        <SyncScheduleForm
                            key={`${shop.slug}:${JSON.stringify(settings)}`}
                            shopSlug={shop.slug}
                            settings={settings}
                            frequencies={frequencies}
                            timezones={timezones}
                        />
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            {settings.isEnabled
                                ? `Scheduled to run ${settings.nextRunAtDiff ?? 'soon'}.`
                                : 'Scheduled syncing is off.'}
                        </p>
                    )}
                </div>

                <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                    <div className="flex flex-wrap items-start justify-between gap-4">
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

                <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
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
