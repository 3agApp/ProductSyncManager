import { Form, Head, router, usePoll } from '@inertiajs/react';
import { PlugZap, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { edit as editProduct } from '@/routes/products';
import { edit as editShop, index as shopsIndex } from '@/routes/shops';
import {
    edit as editTemu,
    test as testConnection,
    update as updateTemu,
} from '@/routes/shops/temu';
import { store as startSync } from '@/routes/shops/temu/syncs';
import type {
    Shop,
    ShopPermissions,
    SyncRunStatus,
    TemuBehaviorOption,
    TemuConnection,
    TemuProblem,
    TemuStatusCount,
    TemuSyncRun,
} from '@/types';

type Props = {
    shop: Shop;
    connection: TemuConnection;
    statusCounts: TemuStatusCount[];
    problems: TemuProblem[];
    currentRun: TemuSyncRun | null;
    recentRuns: TemuSyncRun[];
    behaviors: TemuBehaviorOption[];
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

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString() : '—';
}

export default function ShopTemu({
    shop,
    connection,
    statusCounts,
    problems,
    currentRun,
    recentRuns,
    behaviors,
    permissions,
}: Props) {
    const [testing, setTesting] = useState(false);
    const [starting, setStarting] = useState(false);
    const [syncAfterSync, setSyncAfterSync] = useState(
        connection.syncAfterSync,
    );

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
                toast.success('Temu sync finished.');
            } else if (current === 'failed') {
                toast.error(currentRun?.message ?? 'The Temu sync failed.');
            }
        }

        previousStatus.current = current;
    }, [currentRun?.status, currentRun?.message]);

    const { start, stop } = usePoll(
        3000,
        {
            only: [
                'currentRun',
                'recentRuns',
                'statusCounts',
                'problems',
                'connection',
            ],
        },
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
        router.visit(testConnection(shop.slug), {
            preserveScroll: true,
            onStart: () => setTesting(true),
            onFinish: () => setTesting(false),
        });
    };

    const syncNow = () => {
        router.visit(startSync(shop.slug), {
            preserveScroll: true,
            onStart: () => setStarting(true),
            onFinish: () => setStarting(false),
        });
    };

    return (
        <>
            <Head title={`Temu ${shop.name}`} />

            <div className="flex flex-col space-y-10">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Temu marketplace"
                        description="Create this shop's products on Temu and keep their stock, price and details in step with WooCommerce, through the shop's own self-developed Temu app."
                    />

                    {connection.tokenHasExpired ? (
                        <Alert variant="destructive" data-test="temu-expired">
                            <AlertTitle>
                                The access token has expired
                            </AlertTitle>
                            <AlertDescription>
                                Authorize the app again in Temu Seller Center
                                and paste the new access token below.
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {permissions.canManageSync ? (
                        <Form
                            {...updateTemu.form(shop.slug)}
                            data-test="temu-form"
                            resetOnSuccess={['app_secret', 'access_token']}
                            options={{ preserveScroll: true }}
                            className="space-y-8"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <fieldset className="space-y-4">
                                        <legend className="text-sm font-medium">
                                            App credentials
                                        </legend>
                                        <p className="text-muted-foreground text-sm">
                                            Find the app key and secret in Temu
                                            Seller Center under Open Platform →
                                            Authorization management. The access
                                            token appears there once the app is
                                            approved and authorized. Requests go
                                            to{' '}
                                            <span className="font-mono break-all">
                                                {connection.gateway}
                                            </span>
                                            .
                                        </p>

                                        <div className="grid gap-6 sm:grid-cols-2">
                                            <div className="grid gap-2 sm:col-span-2">
                                                <Label htmlFor="app_key">
                                                    App key
                                                </Label>
                                                <Input
                                                    id="app_key"
                                                    name="app_key"
                                                    autoComplete="off"
                                                    className="font-mono"
                                                    data-test="temu-app-key"
                                                    defaultValue={
                                                        connection.appKey
                                                    }
                                                />
                                                <InputError
                                                    message={errors.app_key}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="app_secret">
                                                    App secret
                                                </Label>
                                                <Input
                                                    id="app_secret"
                                                    name="app_secret"
                                                    type="password"
                                                    autoComplete="new-password"
                                                    data-test="temu-app-secret"
                                                    placeholder={
                                                        connection.hasSecret
                                                            ? 'Saved — leave blank to keep'
                                                            : ''
                                                    }
                                                />
                                                <InputError
                                                    message={errors.app_secret}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="access_token">
                                                    Access token
                                                </Label>
                                                <Input
                                                    id="access_token"
                                                    name="access_token"
                                                    type="password"
                                                    autoComplete="new-password"
                                                    data-test="temu-access-token"
                                                    placeholder={
                                                        connection.hasToken
                                                            ? 'Saved — leave blank to keep'
                                                            : 'Available after approval'
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors.access_token
                                                    }
                                                />
                                            </div>
                                        </div>

                                        {connection.verifiedAt ? (
                                            <p
                                                className="text-muted-foreground text-xs"
                                                data-test="temu-verified"
                                            >
                                                Connected
                                                {connection.mallId
                                                    ? ` to store ${connection.mallId}`
                                                    : ''}{' '}
                                                on{' '}
                                                {formatDate(
                                                    connection.verifiedAt,
                                                )}
                                                .
                                                {connection.tokenExpiresAt
                                                    ? ` The token expires on ${formatDate(connection.tokenExpiresAt)}.`
                                                    : ''}
                                            </p>
                                        ) : null}

                                        {connection.hasSecret &&
                                        connection.hasToken &&
                                        !connection.isUsable ? (
                                            <p className="text-xs text-amber-700 dark:text-amber-400">
                                                The saved secret or token can no
                                                longer be read. Enter them
                                                again.
                                            </p>
                                        ) : null}
                                    </fieldset>

                                    <fieldset className="space-y-4 border-t pt-6">
                                        <legend className="sr-only">
                                            Listing
                                        </legend>
                                        <Heading
                                            variant="small"
                                            title="Listing"
                                            description="Temu picks the category, attributes and shipping template itself, using the store's defaults."
                                        />

                                        <div className="grid gap-6 sm:grid-cols-[1fr_auto]">
                                            <div className="grid gap-2">
                                                <Label htmlFor="default_behavior">
                                                    Default product behavior
                                                </Label>
                                                <Select
                                                    name="default_behavior"
                                                    defaultValue={
                                                        connection.defaultBehavior
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="default_behavior"
                                                        data-test="temu-default-behavior"
                                                        className="w-full sm:w-72"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {behaviors.map(
                                                            (behavior) => (
                                                                <SelectItem
                                                                    key={
                                                                        behavior.value
                                                                    }
                                                                    value={
                                                                        behavior.value
                                                                    }
                                                                >
                                                                    {
                                                                        behavior.label
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <p className="text-muted-foreground text-xs">
                                                    Applies to products set to
                                                    follow the shop default.
                                                    Start with exclude all and
                                                    include one product to try
                                                    the connection out.
                                                </p>
                                                <InputError
                                                    message={
                                                        errors.default_behavior
                                                    }
                                                />
                                            </div>

                                            <div className="grid content-start gap-2">
                                                <Label htmlFor="currency">
                                                    Currency
                                                </Label>
                                                <Input
                                                    id="currency"
                                                    name="currency"
                                                    maxLength={3}
                                                    required
                                                    className="w-24 uppercase"
                                                    data-test="temu-currency"
                                                    defaultValue={
                                                        connection.currency
                                                    }
                                                />
                                                <InputError
                                                    message={errors.currency}
                                                />
                                            </div>
                                        </div>

                                        <p className="text-muted-foreground text-sm">
                                            Prices are sent exactly as the shop
                                            has them. Temu reviews price changes
                                            on listed products, so a new price
                                            can take a while to show.
                                        </p>
                                    </fieldset>

                                    <div className="flex items-start gap-3 rounded-lg border p-4">
                                        <Checkbox
                                            id="sync_after_sync"
                                            name="sync_after_sync"
                                            value="1"
                                            checked={syncAfterSync}
                                            onCheckedChange={(value) =>
                                                setSyncAfterSync(value === true)
                                            }
                                            data-test="temu-sync-after-sync"
                                            className="mt-0.5"
                                        />
                                        <div className="space-y-1">
                                            <Label htmlFor="sync_after_sync">
                                                Sync after every WooCommerce
                                                sync
                                            </Label>
                                            <p className="text-muted-foreground text-sm">
                                                Send changes to Temu each time a
                                                WooCommerce sync finishes, and a
                                                few minutes after webhooks
                                                change products.
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-4">
                                        <Button
                                            type="submit"
                                            data-test="temu-save"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save settings
                                        </Button>

                                        <Button
                                            type="button"
                                            variant="secondary"
                                            data-test="temu-test"
                                            onClick={runTest}
                                            disabled={
                                                testing || !connection.isUsable
                                            }
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
                            {connection.isUsable
                                ? 'This shop lists its products on Temu.'
                                : 'This shop is not connected to Temu yet.'}
                        </p>
                    )}
                </div>

                <div className="space-y-6 border-t pt-8">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <Heading
                            variant="small"
                            title="Products on Temu"
                            description="Where each product sent from this shop stands, as of the last sync."
                        />

                        {permissions.canRunSync ? (
                            <Button
                                onClick={syncNow}
                                data-test="temu-sync"
                                disabled={
                                    starting || isActive || !connection.isUsable
                                }
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

                    {statusCounts.length > 0 ? (
                        <div
                            className="flex flex-wrap gap-2"
                            data-test="temu-status-counts"
                        >
                            {statusCounts.map((item) => (
                                <Badge key={item.status} variant="outline">
                                    {item.label}
                                    <span className="tabular-nums">
                                        {item.count.toLocaleString()}
                                    </span>
                                </Badge>
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Nothing has been sent to Temu yet.
                        </p>
                    )}

                    {currentRun && !isActive && currentRun.message ? (
                        <Alert variant="destructive" data-test="temu-error">
                            <AlertTitle>Last sync failed</AlertTitle>
                            <AlertDescription>
                                {currentRun.message}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    {problems.length > 0 ? (
                        <div className="space-y-3">
                            <h3 className="text-sm font-medium">
                                Needs attention
                            </h3>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Product</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Details</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {problems.map((item) => (
                                            <TableRow
                                                key={item.id}
                                                data-test="temu-problem-row"
                                            >
                                                <TableCell className="max-w-64">
                                                    {item.productId ? (
                                                        <a
                                                            href={
                                                                editProduct([
                                                                    shop.slug,
                                                                    item.productId,
                                                                ]).url
                                                            }
                                                            className="font-medium hover:underline"
                                                        >
                                                            {item.productName}
                                                        </a>
                                                    ) : (
                                                        <span className="font-medium">
                                                            {item.productName}
                                                        </span>
                                                    )}
                                                    <div className="text-muted-foreground font-mono text-xs">
                                                        {item.sku}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {item.statusLabel}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm whitespace-normal">
                                                    {item.message ??
                                                        item.temuStatus ??
                                                        '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    ) : null}
                </div>

                <div className="space-y-6 border-t pt-8">
                    <Heading
                        variant="small"
                        title="Recent syncs"
                        description="The last ten syncs to Temu."
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
                                            Removed
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Failed
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Duration
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recentRuns.map((item) => (
                                        <TableRow
                                            key={item.id}
                                            data-test="temu-run-row"
                                        >
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        STATUS_VARIANTS[
                                                            item.status
                                                        ]
                                                    }
                                                >
                                                    {item.statusLabel}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {item.createdAtDiff} ·{' '}
                                                {item.triggerLabel}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {item.createdProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {item.updatedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {item.removedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {item.failedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatDuration(
                                                    item.durationInSeconds,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">
                            Nothing has been synced to Temu yet.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

ShopTemu.layout = (props: { shop: { name: string; slug: string } }) => ({
    breadcrumbs: [
        { title: 'Shops', href: shopsIndex() },
        { title: props.shop.name, href: editShop(props.shop.slug) },
        { title: 'Temu', href: editTemu(props.shop.slug) },
    ],
});
