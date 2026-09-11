import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    CircleDollarSign,
    MoreHorizontal,
    Package,
    PackageOpen,
    PackageX,
    Eye,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import DeleteProductModal from '@/components/delete-product-modal';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import ProductDetailModal from '@/components/product-detail-modal';
import { ProductStatusBadge } from '@/components/product-status-badge';
import { ProductStockLevel } from '@/components/product-stock-level';
import { SyncProgressBar } from '@/components/sync-progress';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn, formatCurrency } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    create as createProduct,
    edit as editProduct,
} from '@/routes/products';
import type {
    ActiveSyncRun,
    DashboardInvitation,
    Paginated,
    Product,
    ProductFilters,
    ProductStats,
    ProductStatus,
    ProductStatusOption,
    ShopPermissions,
} from '@/types';

type Props = {
    products: Paginated<Product>;
    stats: ProductStats;
    filters: ProductFilters;
    statuses: ProductStatusOption[];
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
};

const ALL_STATUSES = 'all';

export default function Dashboard({
    products,
    stats,
    filters,
    statuses,
    lowStockThreshold,
    permissions,
    pendingInvitations = [],
    activeSyncRun = null,
}: Props) {
    const { currentShop } = usePage().props;
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );
    const [search, setSearch] = useState(filters.search);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [productToDelete, setProductToDelete] = useState<Product | null>(
        null,
    );
    const [detailOpen, setDetailOpen] = useState(false);
    const [productToView, setProductToView] = useState<Product | null>(null);
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => {
        const timeout = searchTimeout;

        return () => clearTimeout(timeout.current);
    }, []);

    // While a sync is running, refresh the table so imported products appear
    // as they land. The poll only starts once there is something to watch.
    const isSyncing = activeSyncRun !== null;

    const { start: startSyncPoll, stop: stopSyncPoll } = usePoll(
        3000,
        { only: ['activeSyncRun', 'products', 'stats'] },
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

    const hasFilters = filters.search !== '' || filters.status !== null;
    const canManageProducts =
        permissions.canUpdateProduct || permissions.canDeleteProduct;

    const applyFilters = (next: Partial<ProductFilters>) => {
        const query = { ...filters, ...next };

        router.get(
            dashboard(currentShop.slug).url,
            {
                ...(query.search ? { search: query.search } : {}),
                ...(query.status ? { status: query.status } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const handleSearchChange = (value: string) => {
        setSearch(value);
        clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(
            () => applyFilters({ search: value.trim() }),
            300,
        );
    };

    const clearFilters = () => {
        clearTimeout(searchTimeout.current);
        setSearch('');
        applyFilters({ search: '', status: null });
    };

    const openDetail = (product: Product) => {
        setProductToView(product);
        setDetailOpen(true);
    };

    const confirmDelete = (product: Product) => {
        setProductToDelete(product);
        setDeleteOpen(true);
    };

    const statCards: StatCard[] = [
        {
            label: 'Total products',
            value: stats.total.toLocaleString(),
            hint: `${stats.active} active · ${stats.draft} draft`,
            icon: Package,
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
                            Products
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Manage the catalog, pricing and inventory for{' '}
                            {currentShop.name}.
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

                <div className="grid auto-rows-min gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {statCards.map((card) => (
                        <div
                            key={card.label}
                            data-test="stat-card"
                            className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                        >
                            <div className="text-muted-foreground flex items-center justify-between text-sm">
                                <span>{card.label}</span>
                                <card.icon
                                    className={cn('size-4', card.iconClassName)}
                                />
                            </div>
                            <div className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                                {card.value}
                            </div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {card.hint}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col rounded-xl border">
                    <div className="flex flex-col gap-2 border-b p-4 sm:flex-row sm:items-center">
                        <div className="relative w-full sm:max-w-xs">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    handleSearchChange(event.target.value)
                                }
                                placeholder="Search by name or SKU"
                                aria-label="Search products"
                                data-test="product-search"
                                className="pl-9"
                            />
                        </div>

                        <Select
                            value={filters.status ?? ALL_STATUSES}
                            onValueChange={(value) =>
                                applyFilters({
                                    status:
                                        value === ALL_STATUSES
                                            ? null
                                            : (value as ProductStatus),
                                })
                            }
                        >
                            <SelectTrigger
                                className="w-full sm:w-40"
                                aria-label="Filter by status"
                                data-test="product-status-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL_STATUSES}>
                                    All statuses
                                </SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem
                                        key={status.value}
                                        value={status.value}
                                    >
                                        {status.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {hasFilters ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                            >
                                <X /> Clear
                            </Button>
                        ) : null}
                    </div>

                    {products.data.length > 0 ? (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4">
                                        Product
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Inventory</TableHead>
                                    <TableHead className="text-right">
                                        Price
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        Updated
                                    </TableHead>
                                    {canManageProducts ? (
                                        <TableHead className="w-12 pr-4">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    ) : null}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {products.data.map((product) => (
                                    <TableRow
                                        key={product.id}
                                        data-test="product-row"
                                    >
                                        <TableCell className="pl-4">
                                            <div className="flex items-center gap-3">
                                                {product.imageUrl ? (
                                                    <img
                                                        src={product.imageUrl}
                                                        alt=""
                                                        loading="lazy"
                                                        data-test="product-thumbnail"
                                                        className="bg-muted size-9 shrink-0 rounded-md object-cover"
                                                    />
                                                ) : (
                                                    <div className="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-md">
                                                        <Package className="size-4" />
                                                    </div>
                                                )}
                                                <div className="min-w-0">
                                                    <button
                                                        type="button"
                                                        data-test="product-detail-open"
                                                        onClick={() =>
                                                            openDetail(product)
                                                        }
                                                        className="hover:text-primary max-w-64 truncate font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {product.name}
                                                    </button>
                                                    <div className="text-muted-foreground font-mono text-xs">
                                                        {product.sku}
                                                    </div>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <ProductStatusBadge
                                                status={product.status}
                                                label={product.statusLabel}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <ProductStockLevel
                                                stock={product.stock}
                                                lowStockThreshold={
                                                    lowStockThreshold
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatCurrency(product.price)}
                                        </TableCell>
                                        <TableCell
                                            className="text-muted-foreground hidden lg:table-cell"
                                            title={
                                                product.updatedAt
                                                    ? new Date(
                                                          product.updatedAt,
                                                      ).toLocaleString()
                                                    : undefined
                                            }
                                        >
                                            {product.updatedAtDiff}
                                        </TableCell>
                                        {canManageProducts ? (
                                            <TableCell className="pr-4 text-right">
                                                <DropdownMenu modal={false}>
                                                    <DropdownMenuTrigger
                                                        asChild
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            data-test="product-actions"
                                                        >
                                                            <MoreHorizontal />
                                                            <span className="sr-only">
                                                                Actions for{' '}
                                                                {product.name}
                                                            </span>
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent
                                                        align="end"
                                                        className="w-40"
                                                    >
                                                        <DropdownMenuItem
                                                            data-test="product-view"
                                                            onSelect={() =>
                                                                openDetail(
                                                                    product,
                                                                )
                                                            }
                                                        >
                                                            <Eye /> View details
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        {permissions.canUpdateProduct ? (
                                                            <DropdownMenuItem
                                                                asChild
                                                                data-test="product-edit"
                                                            >
                                                                <Link
                                                                    href={editProduct(
                                                                        [
                                                                            currentShop.slug,
                                                                            product.id,
                                                                        ],
                                                                    )}
                                                                >
                                                                    <Pencil />{' '}
                                                                    Edit
                                                                </Link>
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                        {permissions.canUpdateProduct &&
                                                        permissions.canDeleteProduct ? (
                                                            <DropdownMenuSeparator />
                                                        ) : null}
                                                        {permissions.canDeleteProduct ? (
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                data-test="product-delete"
                                                                onSelect={() =>
                                                                    confirmDelete(
                                                                        product,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 />{' '}
                                                                Delete
                                                            </DropdownMenuItem>
                                                        ) : null}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        ) : null}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    ) : (
                        <div className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                            <div className="bg-muted flex size-12 items-center justify-center rounded-full">
                                <PackageOpen className="text-muted-foreground size-6" />
                            </div>
                            {hasFilters ? (
                                <>
                                    <div className="space-y-1">
                                        <h2 className="font-medium">
                                            No products match your filters
                                        </h2>
                                        <p className="text-muted-foreground text-sm">
                                            Try a different search term or
                                            status.
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        Clear filters
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <div className="space-y-1">
                                        <h2 className="font-medium">
                                            No products yet
                                        </h2>
                                        <p className="text-muted-foreground text-sm">
                                            Add your first product to start
                                            building the {currentShop.name}{' '}
                                            catalog.
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
                                </>
                            )}
                        </div>
                    )}

                    {products.total > 0 ? (
                        <div className="flex flex-col items-center justify-between gap-3 border-t px-4 py-3 text-sm sm:flex-row">
                            <p className="text-muted-foreground">
                                Showing{' '}
                                <span className="text-foreground font-medium">
                                    {products.from}–{products.to}
                                </span>{' '}
                                of{' '}
                                <span className="text-foreground font-medium">
                                    {products.total}
                                </span>{' '}
                                products
                            </p>

                            {products.last_page > 1 ? (
                                <nav
                                    className="flex items-center gap-1"
                                    aria-label="Pagination"
                                >
                                    <PaginationArrow
                                        href={products.prev_page_url}
                                        label="Previous page"
                                        icon={ChevronLeft}
                                    />
                                    {products.links
                                        .slice(1, -1)
                                        .map((link, index) =>
                                            link.url ? (
                                                <Button
                                                    key={`${link.label}-${index}`}
                                                    variant={
                                                        link.active
                                                            ? 'outline'
                                                            : 'ghost'
                                                    }
                                                    size="sm"
                                                    className="min-w-8 px-2 tabular-nums"
                                                    asChild
                                                >
                                                    <Link
                                                        href={link.url}
                                                        preserveScroll
                                                        preserveState
                                                        aria-current={
                                                            link.active
                                                                ? 'page'
                                                                : undefined
                                                        }
                                                    >
                                                        {link.label}
                                                    </Link>
                                                </Button>
                                            ) : (
                                                <span
                                                    key={`${link.label}-${index}`}
                                                    className="text-muted-foreground px-2"
                                                >
                                                    {link.label}
                                                </span>
                                            ),
                                        )}
                                    <PaginationArrow
                                        href={products.next_page_url}
                                        label="Next page"
                                        icon={ChevronRight}
                                    />
                                </nav>
                            ) : null}
                        </div>
                    ) : null}
                </div>
            </div>

            <DeleteProductModal
                shop={currentShop}
                product={productToDelete}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
            />

            <ProductDetailModal
                product={productToView}
                open={detailOpen}
                onOpenChange={setDetailOpen}
            />
        </>
    );
}

function PaginationArrow({
    href,
    label,
    icon: Icon,
}: {
    href: string | null;
    label: string;
    icon: LucideIcon;
}) {
    if (!href) {
        return (
            <Button variant="ghost" size="icon" className="size-8" disabled>
                <Icon />
                <span className="sr-only">{label}</span>
            </Button>
        );
    }

    return (
        <Button variant="ghost" size="icon" className="size-8" asChild>
            <Link href={href} preserveScroll preserveState>
                <Icon />
                <span className="sr-only">{label}</span>
            </Link>
        </Button>
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
