import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    ArrowUpRight,
    ArrowRight,
    Layers3,
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
    Product,
    ProductStats,
    ShopPermissions,
} from '@/types';

type Props = {
    stats: ProductStats;
    recentProducts: Product[];
    lowStockThreshold: number;
    permissions: ShopPermissions;
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
    activeSyncRun = null,
}: Props) {
    const { currentShop } = usePage().props;
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
            value: formatCurrency(stats.inventoryValue, currentShop.currency),
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
    ];

    const catalogSegments = [
        {
            label: 'Active',
            count: stats.active,
            color: 'bg-emerald-500',
            status: 'active',
        },
        {
            label: 'Draft',
            count: stats.draft,
            color: 'bg-amber-400',
            status: 'draft',
        },
        {
            label: 'Archived',
            count: stats.archived,
            color: 'bg-slate-400',
            status: 'archived',
        },
    ];

    return (
        <>
            <Head title="Dashboard" />

            <div className="workspace-page">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="page-heading">
                        <h1 className="page-title">Dashboard</h1>
                        <p className="text-muted-foreground text-sm">
                            Your catalog, inventory and latest changes in one
                            place.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={productsIndex(currentShop.slug)}>
                                Browse catalog <ArrowUpRight />
                            </Link>
                        </Button>
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
                </div>

                {activeSyncRun ? (
                    <div
                        data-test="sync-banner"
                        role="status"
                        className="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
                    >
                        <div className="flex items-center gap-2 text-sm">
                            <RefreshCw className="size-4 motion-safe:animate-spin" />
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

                <div className="grid auto-rows-min gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {statCards.map((card) => (
                        <StatTile key={card.label} card={card} />
                    ))}
                </div>

                <div className="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_300px]">
                    <section
                        className="bg-card min-w-0 overflow-hidden rounded-2xl border shadow-xs"
                        aria-labelledby="recent-heading"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-5">
                            <div>
                                <h2
                                    id="recent-heading"
                                    className="font-semibold"
                                >
                                    Recently updated
                                </h2>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    The latest changes across your catalog.
                                </p>
                            </div>

                            <Button variant="ghost" size="sm" asChild>
                                <Link
                                    href={productsIndex(currentShop.slug)}
                                    data-test="view-all-products"
                                >
                                    View all products{' '}
                                    <ArrowRight className="size-3.5" />
                                </Link>
                            </Button>
                        </div>

                        {recentProducts.length > 0 ? (
                            <ul className="divide-y">
                                {recentProducts.map((product) => (
                                    <li
                                        key={product.id}
                                        data-test="recent-product"
                                        className="hover:bg-muted/40 grid grid-cols-[44px_minmax(0,1fr)] items-center gap-x-3 gap-y-2 px-5 py-4 transition-colors sm:grid-cols-[44px_minmax(0,1fr)_auto]"
                                    >
                                        {product.imageUrl ? (
                                            <img
                                                src={product.imageUrl}
                                                alt=""
                                                loading="lazy"
                                                className="bg-muted size-11 shrink-0 rounded-xl object-cover"
                                            />
                                        ) : (
                                            <div className="bg-muted text-muted-foreground flex size-11 shrink-0 items-center justify-center rounded-xl">
                                                <Package className="size-4" />
                                            </div>
                                        )}

                                        <div className="min-w-0 flex-1">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    openDetail(product)
                                                }
                                                className="hover:text-primary focus-visible:ring-ring max-w-full truncate rounded text-left text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                {product.name}
                                            </button>
                                            <div className="text-muted-foreground mt-1 truncate font-mono text-xs">
                                                {product.sku}
                                            </div>
                                        </div>

                                        <div className="col-start-2 flex flex-wrap items-center gap-2 text-xs sm:col-start-3 sm:row-start-1 sm:justify-end">
                                            <ProductStatusBadge
                                                status={product.status}
                                                label={product.statusLabel}
                                            />
                                            <span className="text-muted-foreground hidden sm:inline">
                                                ·
                                            </span>
                                            <ProductStockLevel
                                                stock={product.stock}
                                                lowStockThreshold={
                                                    lowStockThreshold
                                                }
                                            />
                                            <span className="text-muted-foreground w-full text-xs sm:text-right">
                                                {product.updatedAtDiff}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                                <div className="bg-muted flex size-12 items-center justify-center rounded-full">
                                    <PackageOpen className="text-muted-foreground size-6" />
                                </div>
                                <div className="space-y-1">
                                    <h3 className="font-medium">
                                        No products yet
                                    </h3>
                                    <p className="text-muted-foreground text-sm">
                                        Add your first product to start building
                                        the {currentShop.name} catalog.
                                    </p>
                                </div>
                                {permissions.canCreateProduct ? (
                                    <Button size="sm" asChild>
                                        <Link
                                            href={createProduct(
                                                currentShop.slug,
                                            )}
                                        >
                                            <Plus /> Add product
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                        )}
                    </section>

                    <aside className="space-y-4" aria-label="Catalog overview">
                        <section className="bg-card rounded-2xl border p-5 shadow-xs">
                            <div className="flex items-center justify-between">
                                <h2 className="font-semibold">
                                    Catalog breakdown
                                </h2>
                                <Layers3 className="text-muted-foreground size-4" />
                            </div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Product status across your shop
                            </p>
                            <div className="mt-6 flex items-baseline gap-2">
                                <span className="text-3xl font-semibold tracking-tight tabular-nums">
                                    {stats.total.toLocaleString()}
                                </span>
                                <span className="text-muted-foreground text-sm">
                                    products
                                </span>
                            </div>
                            <div
                                className="bg-muted mt-4 flex h-2 overflow-hidden rounded-full"
                                aria-hidden="true"
                            >
                                {catalogSegments.map((segment) => (
                                    <div
                                        key={segment.label}
                                        className={segment.color}
                                        style={{
                                            width: `${stats.total > 0 ? (segment.count / stats.total) * 100 : 0}%`,
                                        }}
                                    />
                                ))}
                            </div>
                            <div className="mt-4 space-y-1">
                                {catalogSegments.map((segment) => (
                                    <Link
                                        key={segment.label}
                                        href={productsIndex(currentShop.slug, {
                                            query: { status: segment.status },
                                        })}
                                        className="hover:bg-muted focus-visible:ring-ring flex items-center gap-2 rounded-lg px-2 py-2.5 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <span
                                            className={cn(
                                                'size-2 rounded-full',
                                                segment.color,
                                            )}
                                        />
                                        <span className="flex-1">
                                            {segment.label}
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {segment.count.toLocaleString()}
                                        </span>
                                        <ArrowUpRight className="text-muted-foreground ml-1 size-3.5" />
                                    </Link>
                                ))}
                            </div>
                        </section>
                        <section className="bg-card rounded-2xl border p-5 shadow-xs">
                            <h2 className="font-semibold">
                                Keep things organized
                            </h2>
                            <p className="text-muted-foreground mt-1 text-xs">
                                Make your catalog easier to navigate.
                            </p>
                            <div className="mt-4 space-y-2">
                                {[
                                    {
                                        label: 'Categories',
                                        count: stats.categories,
                                        icon: FolderTree,
                                        href: categoriesIndex(currentShop.slug),
                                    },
                                    {
                                        label: 'Brands',
                                        count: stats.brands,
                                        icon: Tags,
                                        href: brandsIndex(currentShop.slug),
                                    },
                                ].map((item) => (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        className="hover:bg-muted focus-visible:ring-ring flex items-center gap-3 rounded-xl border px-3 py-3 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <item.icon className="text-muted-foreground size-4" />
                                        <span className="flex-1">
                                            {item.label}
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {item.count.toLocaleString()}
                                        </span>
                                        <ArrowUpRight className="text-muted-foreground size-3.5" />
                                    </Link>
                                ))}
                            </div>
                        </section>
                    </aside>
                </div>
                <p className="text-muted-foreground text-xs">
                    Overview for{' '}
                    <span className="font-medium">{currentShop.name}</span> ·
                    Inventory figures include all product statuses.
                </p>
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
                <span
                    className={cn(
                        'bg-muted flex size-9 shrink-0 items-center justify-center rounded-xl',
                        card.iconClassName,
                    )}
                >
                    <card.icon className="size-4" />
                </span>
            </div>
            <div className="mt-4 text-3xl font-semibold tracking-tight break-words tabular-nums">
                {card.value}
            </div>
            <p className="text-muted-foreground mt-2 text-xs leading-relaxed">
                {card.hint}
            </p>
        </>
    );

    const className = 'bg-card min-w-0 rounded-2xl border p-5 shadow-xs';

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
            className={cn(
                className,
                'hover:border-foreground/25 focus-visible:ring-ring transition-colors focus-visible:ring-2 focus-visible:outline-none',
            )}
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
