import { Form, Head, router, usePoll } from '@inertiajs/react';
import { Download, PlugZap, Upload } from 'lucide-react';
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
import { SectionNav } from '@/components/ui/section-nav';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { edit as editShop, index as shopsIndex } from '@/routes/shops';
import {
    download,
    edit as editNalda,
    test as testConnection,
    update as updateNalda,
} from '@/routes/shops/nalda';
import { store as startExport } from '@/routes/shops/nalda/exports';
import type {
    NaldaBehaviorOption,
    NaldaConnection,
    NaldaExport,
    NaldaPriceModeOption,
    Shop,
    ShopPermissions,
    ShopTax,
    SyncRunStatus,
} from '@/types';

type Props = {
    shop: Shop;
    connection: NaldaConnection;
    catalog: { withGtin: number; withoutGtin: number };
    currentExport: NaldaExport | null;
    recentExports: NaldaExport[];
    behaviors: NaldaBehaviorOption[];
    priceModes: NaldaPriceModeOption[];
    shopTax: ShopTax;
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

export default function ShopNalda({
    shop,
    connection,
    catalog,
    currentExport,
    recentExports,
    behaviors,
    priceModes,
    shopTax,
    permissions,
}: Props) {
    const [testing, setTesting] = useState(false);
    const [starting, setStarting] = useState(false);
    const [uploadAfterSync, setUploadAfterSync] = useState(
        connection.uploadAfterSync,
    );

    const isActive = currentExport?.isActive ?? false;

    // A finished job cannot flash a toast from the server, because it runs
    // outside any request. Watch the polled status change instead.
    const previousStatus = useRef<SyncRunStatus | null>(
        currentExport?.status ?? null,
    );

    useEffect(() => {
        const previous = previousStatus.current;
        const current = currentExport?.status ?? null;

        if (previous && current && previous !== current) {
            if (current === 'completed') {
                toast.success('Product file uploaded to Nalda.');
            } else if (current === 'failed') {
                toast.error(
                    currentExport?.message ?? 'The upload to Nalda failed.',
                );
            }
        }

        previousStatus.current = current;
    }, [currentExport?.status, currentExport?.message]);

    const { start, stop } = usePoll(
        3000,
        { only: ['currentExport', 'recentExports', 'connection'] },
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
            '[data-test="nalda-form"]',
        );
        const data = form ? Object.fromEntries(new FormData(form)) : {};

        router.visit(testConnection(shop.slug), {
            data: {
                sftp_username: data.sftp_username ?? '',
                sftp_password: data.sftp_password ?? '',
            },
            preserveScroll: true,
            onStart: () => setTesting(true),
            onFinish: () => setTesting(false),
        });
    };

    const uploadNow = () => {
        router.visit(startExport(shop.slug), {
            preserveScroll: true,
            onStart: () => setStarting(true),
            onFinish: () => setStarting(false),
        });
    };

    return (
        <>
            <Head title={`Nalda ${shop.name}`} />

            <div className="flex flex-col space-y-10">
                <SectionNav
                    sections={[
                        { id: 'nalda-connection', label: 'Connection' },
                        { id: 'nalda-defaults', label: 'Product defaults' },
                        { id: 'nalda-file', label: 'Product file' },
                        { id: 'nalda-history', label: 'History' },
                    ]}
                    idPrefix="nalda"
                    orientation="horizontal"
                    className="bg-card sticky top-2 z-10"
                />

                <div
                    id="nalda-connection"
                    tabIndex={-1}
                    className="scroll-mt-20 space-y-6 outline-none"
                >
                    <Heading
                        variant="small"
                        title="Nalda marketplace"
                        description="List this shop's products on Nalda. Every product with a GTIN is sent in a CSV file, and Nalda matches products on their GTIN."
                    />

                    {permissions.canManageSync ? (
                        <Form
                            {...updateNalda.form(shop.slug)}
                            data-test="nalda-form"
                            resetOnSuccess={['sftp_password']}
                            options={{ preserveScroll: true }}
                            className="space-y-8"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <fieldset className="space-y-4">
                                        <legend className="text-sm font-medium">
                                            SFTP upload
                                        </legend>
                                        <p className="text-muted-foreground text-sm">
                                            Find the user and password in the
                                            Nalda Seller Portal under Products.
                                            Files are uploaded to{' '}
                                            <span className="font-mono">
                                                {connection.sftpHost}:
                                                {connection.sftpPort}
                                            </span>
                                            .
                                        </p>

                                        <div className="grid gap-6 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="sftp_username">
                                                    SFTP user
                                                </Label>
                                                <Input
                                                    id="sftp_username"
                                                    name="sftp_username"
                                                    autoComplete="off"
                                                    data-test="nalda-username"
                                                    defaultValue={
                                                        connection.sftpUsername
                                                    }
                                                    placeholder="nalda-seller-…"
                                                />
                                                <InputError
                                                    message={
                                                        errors.sftp_username
                                                    }
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="sftp_password">
                                                    SFTP password
                                                </Label>
                                                <Input
                                                    id="sftp_password"
                                                    name="sftp_password"
                                                    type="password"
                                                    autoComplete="new-password"
                                                    data-test="nalda-password"
                                                    placeholder={
                                                        connection.hasPassword
                                                            ? 'Saved — leave blank to keep'
                                                            : ''
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors.sftp_password
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <div className="grid gap-2 sm:max-w-xs">
                                            <Label htmlFor="file_name">
                                                File name
                                            </Label>
                                            <Input
                                                id="file_name"
                                                name="file_name"
                                                autoComplete="off"
                                                data-test="nalda-file-name"
                                                defaultValue={
                                                    connection.fileName
                                                }
                                                required
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                Uploaded to the SFTP root,
                                                replacing the previous file.
                                            </p>
                                            <InputError
                                                message={errors.file_name}
                                            />
                                        </div>

                                        {connection.hasPassword &&
                                        !connection.isUsable ? (
                                            <p className="text-xs text-amber-700 dark:text-amber-400">
                                                The saved password can no longer
                                                be read. Enter it again.
                                            </p>
                                        ) : null}
                                    </fieldset>

                                    <fieldset
                                        id="nalda-defaults"
                                        tabIndex={-1}
                                        className="scroll-mt-20 space-y-4 border-t pt-6 outline-none"
                                    >
                                        <legend className="sr-only">
                                            Product defaults
                                        </legend>
                                        <Heading
                                            variant="small"
                                            title="Product defaults"
                                            description="Nalda needs these for every product, and the catalog does not store them."
                                        />

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
                                                    data-test="nalda-default-behavior"
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
                                                                {behavior.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <p className="text-muted-foreground text-xs">
                                                Applies to products set to
                                                follow the shop default. A
                                                product can be set to always
                                                include or always exclude on its
                                                edit page.
                                            </p>
                                            <InputError
                                                message={
                                                    errors.default_behavior
                                                }
                                            />
                                        </div>

                                        <div className="grid gap-6 sm:grid-cols-3">
                                            <Field
                                                id="country"
                                                label="Country"
                                                defaultValue={
                                                    connection.country
                                                }
                                                maxLength={2}
                                                error={errors.country}
                                            />
                                            <Field
                                                id="currency"
                                                label="Currency"
                                                defaultValue={
                                                    connection.currency
                                                }
                                                maxLength={3}
                                                error={errors.currency}
                                            />
                                            <Field
                                                id="condition"
                                                label="Condition"
                                                defaultValue={
                                                    connection.condition
                                                }
                                                error={errors.condition}
                                            />
                                            <div className="grid gap-2">
                                                <Label>Tax rate (%)</Label>
                                                <p
                                                    className="flex h-9 items-center text-sm tabular-nums"
                                                    data-test="nalda-tax-rate"
                                                >
                                                    {shopTax.naldaTaxRate}
                                                </p>
                                                <p className="text-muted-foreground text-xs">
                                                    From the shop's tax settings
                                                    ({shopTax.taxRate}%). Nalda
                                                    accepts 8.1 or 2.6.
                                                </p>
                                            </div>
                                            <Field
                                                id="delivery_time_days"
                                                label="Delivery time (days)"
                                                type="number"
                                                step="1"
                                                min="1"
                                                defaultValue={String(
                                                    connection.deliveryTimeDays,
                                                )}
                                                error={
                                                    errors.delivery_time_days
                                                }
                                            />
                                            <Field
                                                id="return_days"
                                                label="Return period (days)"
                                                type="number"
                                                step="1"
                                                min="0"
                                                defaultValue={String(
                                                    connection.returnDays,
                                                )}
                                                error={errors.return_days}
                                            />
                                        </div>
                                    </fieldset>

                                    <div className="grid gap-2 sm:max-w-sm">
                                        <Label htmlFor="price_mode">
                                            Prices
                                        </Label>
                                        {shopTax.pricesIncludeTax ? (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="price_mode"
                                                    value="as_is"
                                                />
                                                <p
                                                    className="text-sm"
                                                    data-test="nalda-price-mode-fixed"
                                                >
                                                    Sent as they are. This
                                                    shop's prices already
                                                    include VAT.
                                                </p>
                                            </>
                                        ) : (
                                            <>
                                                <Select
                                                    name="price_mode"
                                                    defaultValue={
                                                        connection.priceMode
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="price_mode"
                                                        data-test="nalda-price-mode"
                                                        className="w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {priceModes.map(
                                                            (mode) => (
                                                                <SelectItem
                                                                    key={
                                                                        mode.value
                                                                    }
                                                                    value={
                                                                        mode.value
                                                                    }
                                                                >
                                                                    {mode.label}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                <p className="text-muted-foreground text-xs">
                                                    This shop's prices are
                                                    entered without VAT. Adding
                                                    VAT uses the shop's rate and
                                                    rounds to 0.05.
                                                </p>
                                            </>
                                        )}
                                        <InputError
                                            message={errors.price_mode}
                                        />
                                    </div>

                                    <div className="flex items-start gap-3 rounded-lg border p-4">
                                        <Checkbox
                                            id="upload_after_sync"
                                            name="upload_after_sync"
                                            value="1"
                                            checked={uploadAfterSync}
                                            onCheckedChange={(value) =>
                                                setUploadAfterSync(
                                                    value === true,
                                                )
                                            }
                                            data-test="nalda-upload-after-sync"
                                            className="mt-0.5"
                                        />
                                        <div className="space-y-1">
                                            <Label htmlFor="upload_after_sync">
                                                Upload after every sync
                                            </Label>
                                            <p className="text-muted-foreground text-sm">
                                                Send a fresh product file to
                                                Nalda each time a WooCommerce
                                                sync finishes, and a few minutes
                                                after webhooks change products.
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-4">
                                        <Button
                                            type="submit"
                                            data-test="nalda-save"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save settings
                                        </Button>

                                        <Button
                                            type="button"
                                            variant="secondary"
                                            data-test="nalda-test"
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
                            {connection.isUsable
                                ? 'This shop uploads its products to Nalda.'
                                : 'This shop is not connected to Nalda yet.'}
                        </p>
                    )}
                </div>

                <div className="space-y-6 border-t pt-8">
                    <div
                        id="nalda-file"
                        tabIndex={-1}
                        className="flex scroll-mt-20 flex-wrap items-start justify-between gap-4 outline-none"
                    >
                        <Heading
                            variant="small"
                            title="Product file"
                            description={`${catalog.withGtin.toLocaleString()} products have a GTIN and are sent. ${catalog.withoutGtin.toLocaleString()} without one are left out.`}
                        />

                        {permissions.canRunSync ? (
                            <div className="flex flex-wrap gap-2">
                                <Button variant="secondary" asChild>
                                    <a
                                        href={
                                            connection.isConfigured
                                                ? download(shop.slug).url
                                                : undefined
                                        }
                                        aria-disabled={!connection.isConfigured}
                                        className={
                                            connection.isConfigured
                                                ? undefined
                                                : 'pointer-events-none opacity-50'
                                        }
                                        data-test="nalda-download"
                                    >
                                        <Download className="size-4" />
                                        Download CSV
                                    </a>
                                </Button>

                                <Button
                                    onClick={uploadNow}
                                    data-test="nalda-upload"
                                    disabled={
                                        starting ||
                                        isActive ||
                                        !connection.isUsable
                                    }
                                >
                                    {starting || isActive ? (
                                        <Spinner />
                                    ) : (
                                        <Upload className="size-4" />
                                    )}
                                    {isActive ? 'Uploading…' : 'Upload now'}
                                </Button>
                            </div>
                        ) : null}
                    </div>

                    <p className="text-muted-foreground text-sm">
                        Nalda only removes a product when the file says so.
                        Excluded, draft and archived products are therefore sent
                        with <span className="font-mono">delete_product</span>{' '}
                        set, and a GTIN that was listed before but is no longer
                        in the catalog gets its own removal row.
                    </p>

                    {!connection.isConfigured ? (
                        <p className="text-muted-foreground text-sm">
                            Save the settings above to download or upload the
                            product file.
                        </p>
                    ) : null}

                    {currentExport && !isActive && currentExport.message ? (
                        <Alert variant="destructive" data-test="nalda-error">
                            <AlertTitle>Last upload failed</AlertTitle>
                            <AlertDescription>
                                {currentExport.message}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                </div>

                <div
                    id="nalda-history"
                    tabIndex={-1}
                    className="scroll-mt-20 space-y-6 border-t pt-8 outline-none"
                >
                    <Heading
                        variant="small"
                        title="Recent uploads"
                        description="The last ten product files sent to Nalda."
                    />

                    {recentExports.length > 0 ? (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Started</TableHead>
                                        <TableHead className="text-right">
                                            Listed
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Removed
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
                                    {recentExports.map((item) => (
                                        <TableRow
                                            key={item.id}
                                            data-test="nalda-export-row"
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
                                                {item.listedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {item.removedProducts.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {item.skippedProducts.toLocaleString()}
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
                            Nothing has been uploaded to Nalda yet.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

function Field({
    id,
    label,
    error,
    ...props
}: {
    id: string;
    label: string;
    error?: string;
} & React.ComponentProps<typeof Input>) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={id}
                data-test={`nalda-${id}`}
                required
                {...props}
            />
            <InputError message={error} />
        </div>
    );
}

ShopNalda.layout = (props: { shop: { name: string; slug: string } }) => ({
    breadcrumbs: [
        { title: 'Shops', href: shopsIndex() },
        { title: props.shop.name, href: editShop(props.shop.slug) },
        { title: 'Nalda', href: editNalda(props.shop.slug) },
    ],
});
