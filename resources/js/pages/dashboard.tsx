import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    CircleDollarSign,
    FolderTree,
    Package,
    PackageOpen,
    PackageX,
    Plus,
    RefreshCw,
    Tags,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import ProductDetailModal from '@/components/product-detail-modal';
import { ProductStatusBadge } from '@/components/product-status-badge';
import { ProductStockLevel } from '@/components/product-stock-level';
import { SyncProgressBar } from '@/components/sync-progress';
import { Button } from '@/components/ui/button';
import { cn, formatCurrency } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as brandsIndex } from '@/routes/brands';
import { index as categoriesIndex } from '@/routes/categories';
import {
    create as createProduct,
    index as productsIndex,
} from '@/routes/products';
import type {
    ActiveSyncRun,
    DashboardInvitation,
    Product,
    ProductStats,
    ShopPermissions,
} from '@/types';

type Props = {
    stats: ProductStats;
    recentProducts: Product[];
    lowStockThreshold: number;
    permissions: ShopPermissions;
    pendingInvitations?: DashboardInvitation[];
    activeSyncRun?: ActiveSyncRun | null;
};

type StatCard = {
    label: string;
    value: string;
    hint: string;
    icon: LucideIcon;
    iconClassName?: string;
    href?: string;
};

export default function Dashboard({
    stats,
    recentProducts,
    lowStockThreshold,
    permissions,
    pendingInvitations = [],
    activeSyncRun = null,
}: Props) {
    const { currentShop } = usePage().props;
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );
    const [detailOpen, setDetailOpen] = useState(false);
    const [productToView, setProductToView] = useState<Product | null>(null);

    // While a sync is running, refresh the figures so imported products are
    // counted as they land. The poll only starts once there is something to
    // watch.
    const isSyncing = activeSyncRun !== null;

    const { start: startSyncPoll, stop: stopSyncPoll } = usePoll(
        3000,
        { only: ['activeSyncRun', 'stats', 'recentProducts'] },
        { autoStart: isSyncing, keepAlive: false, mode: 'cancel' },
    );

    useEffect(() => {
        if (isSyncing) {
            startSyncPoll();
        } else {
            stopSyncPoll();
        }
    }, [isSyncing, startSyncPoll, stopSyncPoll]);

    if (!currentShop) {
        return null;
    }

    const openDetail = (product: Product) => {
        setProductToView(product);
        setDetailOpen(true);
    };

    const statCards: StatCard[] = [
        {
            label: 'Total products',
            value: stats.total.toLocaleString(),
            hint: `${stats.active} active · ${stats.draft} draft`,
            icon: Package,
            href: productsIndex(currentShop.slug).url,
        },
        {
            label: 'Inventory value',
            value: formatCurrency(stats.inventoryValue),
            hint: 'Price × stock on hand',
            icon: CircleDollarSign,
        },
        {
            label: 'Low stock',
            value: stats.lowStock.toLocaleString(),
            hint: `${lowStockThreshold} units or fewer left`,
            icon: AlertTriangle,
            iconClassName:
                stats.lowStock > 0
                    ? 'text-amber-600 dark:text-amber-400'
                    : undefined,
        },
        {
            label: 'Out of stock',
            value: stats.outOfStock.toLocaleString(),
            hint: 'Needs restocking',
            icon: PackageX,
            iconClassName:
                stats.outOfStock > 0
                    ? 'text-red-600 dark:text-red-400'
                    : undefined,
        },
        {
            label: 'Categories',
            value: stats.categories.toLocaleString(),
            hint: 'How the catalog is filed',
            icon: FolderTree,
            href: categoriesIndex(currentShop.slug).url,
        },
        {
            label: 'Brands',
            value: stats.brands.toLocaleString(),
            hint: 'Names on the shelf',
            icon: Tags,
            href: brandsIndex(currentShop.slug).url,
        },
    ];

    return (
        <>
            <Head title="Dashboard" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-0.5">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Dashboard
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            How {currentShop.name} is doing at a glance.
                        </p>
                    </div>

                    {permissions.canCreateProduct ? (
                        <Button asChild>
                            <Link
                                href={createProduct(currentShop.slug)}
                                data-test="add-product-button"
                            >
                                <Plus /> Add product
                            </Link>
                        </Button>
                    ) : null}
                </div>

                {activeSyncRun ? (
                    <div
                        data-test="sync-banner"
                        className="border-sidebar-border/70 dark:border-sidebar-border space-y-3 rounded-xl border p-4"
                    >
                        <div className="flex items-center gap-2 text-sm">
                            <RefreshCw className="size-4 animate-spin" />
                            <span>
                                {activeSyncRun.status === 'queued'
                                    ? 'Sync queued…'
                                    : activeSyncRun.totalProducts === null
                                      ? `Syncing ${activeSyncRun.processedProducts.toLocaleString()} products…`
                                      : `Syncing ${activeSyncRun.processedProducts.toLocaleString()} of ${activeSyncRun.totalProducts.toLocaleString()} products…`}
                            </span>
                        </div>

                        <SyncProgressBar percent={activeSyncRun.progress} />
                    </div>
                ) : null}

                <div className="grid auto-rows-min gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {statCards.map((card) => (
                        <StatTile key={card.label} card={card} />
                    ))}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col rounded-xl border">
                    <div className="flex items-center justify-between gap-4 border-b p-4">
                        <h2 className="font-medium">Recently updated</h2>

                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={productsIndex(currentShop.slug)}
                                data-test="view-all-products"
                            >
                                View all products
                            </Link>
                        </Button>
                    </div>

                    {recentProducts.length > 0 ? (
                        <ul className="divide-y">
                            {recentProducts.map((product) => (
                                <li
                                    key={product.id}
                                    data-test="recent-product"
                                    className="flex items-center gap-3 p-4"
                                >
                                    {product.imageUrl ? (
                                        <img
                                            src={product.imageUrl}
                                            alt=""
                                            loading="lazy"
                                            className="bg-muted size-9 shrink-0 rounded-md object-cover"
                                        />
                                    ) : (
                                        <div className="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-md">
                                            <Package className="size-4" />
                                        </div>
                                    )}

                                    <div className="min-w-0 flex-1">
                                        <button
                                            type="button"
                                            onClick={() => openDetail(product)}
                                            className="hover:text-primary max-w-full truncate font-medium underline-offset-4 hover:underline"
                                        >
                                            {product.name}
                                        </button>
                                        <div className="text-muted-foreground font-mono text-xs">
                                            {product.sku}
                                        </div>
                                    </div>

                                    <ProductStockLevel
                                        stock={product.stock}
                                        lowStockThreshold={lowStockThreshold}
                                    />

                                    <ProductStatusBadge
                                        status={product.status}
                                        label={product.statusLabel}
                                    />

                                    <span className="text-muted-foreground hidden w-28 text-right text-sm sm:block">
                                        {product.updatedAtDiff}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                            <div className="bg-muted flex size-12 items-center justify-center rounded-full">
                                <PackageOpen className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h3 className="font-medium">No products yet</h3>
                                <p className="text-muted-foreground text-sm">
                                    Add your first product to start building the{' '}
                                    {currentShop.name} catalog.
                                </p>
                            </div>
                            {permissions.canCreateProduct ? (
                                <Button size="sm" asChild>
                                    <Link
                                        href={createProduct(currentShop.slug)}
                                    >
                                        <Plus /> Add product
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    )}
                </div>
            </div>

            <ProductDetailModal
                product={productToView}
                open={detailOpen}
                onOpenChange={setDetailOpen}
            />
        </>
    );
}

function StatTile({ card }: { card: StatCard }) {
    const body = (
        <>
            <div className="text-muted-foreground flex items-center justify-between text-sm">
                <span>{card.label}</span>
                <card.icon className={cn('size-4', card.iconClassName)} />
            </div>
            <div className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                {card.value}
            </div>
            <p className="text-muted-foreground mt-1 text-xs">{card.hint}</p>
        </>
    );

    const className =
        'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';

    if (!card.href) {
        return (
            <div data-test="stat-card" className={className}>
                {body}
            </div>
        );
    }

    return (
        <Link
            href={card.href}
            data-test="stat-card"
            className={cn(className, 'hover:border-sidebar-border transition')}
        >
            {body}
        </Link>
    );
}

Dashboard.layout = (props: { currentShop?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentShop ? dashboard(props.currentShop.slug) : '/',
        },
    ],
});
