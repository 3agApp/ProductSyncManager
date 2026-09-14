import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    Eye,
    MoreHorizontal,
    Package,
    PackageOpen,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import DeleteProductModal from '@/components/delete-product-modal';
import ProductBulkBar from '@/components/product-bulk-bar';
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
import { Checkbox } from '@/components/ui/checkbox';
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
    index as productsIndex,
} from '@/routes/products';
import type {
    ActiveSyncRun,
    FilterOption,
    Paginated,
    Product,
    ProductFilters,
    ProductSort,
    ProductStatus,
    ProductStatusOption,
    ShopPermissions,
} from '@/types';

type Props = {
    products: Paginated<Product>;
    filters: ProductFilters;
    statuses: ProductStatusOption[];
    categoryOptions: FilterOption[];
    brandOptions: FilterOption[];
    pageSizes: number[];
    lowStockThreshold: number;
    permissions: ShopPermissions;
    activeSyncRun?: ActiveSyncRun | null;
};

const ALL = 'all';
const NONE = 'none';

export default function ProductIndex({
    products,
    filters,
    statuses,
    categoryOptions,
    brandOptions,
    pageSizes,
    lowStockThreshold,
    permissions,
    activeSyncRun = null,
}: Props) {
    const { currentShop } = usePage().props;
    const [search, setSearch] = useState(filters.search);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [productToDelete, setProductToDelete] = useState<Product | null>(
        null,
    );
    const [detailOpen, setDetailOpen] = useState(false);
    const [productToView, setProductToView] = useState<Product | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
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
        { only: ['activeSyncRun', 'products'] },
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

    const hasFilters =
        filters.search !== '' ||
        filters.status !== null ||
        filters.category !== null ||
        filters.brand !== null;

    const canManageProducts =
        permissions.canUpdateProduct || permissions.canDeleteProduct;

    // Everything the list is showing lives in the query string, so a view
    // worth keeping can be bookmarked or passed to someone else.
    const applyFilters = (next: Partial<ProductFilters>) => {
        clearTimeout(searchTimeout.current);
        const query = { ...filters, search: search.trim(), ...next };

        setSelected([]);

        router.get(
            productsIndex(currentShop.slug).url,
            {
                ...(query.search ? { search: query.search } : {}),
                ...(query.status ? { status: query.status } : {}),
                ...(query.category ? { category: query.category } : {}),
                ...(query.brand ? { brand: query.brand } : {}),
                ...(query.sort !== 'name' ? { sort: query.sort } : {}),
                ...(query.direction !== defaultDirection(query.sort)
                    ? { direction: query.direction }
                    : {}),
                ...(query.perPage !== pageSizes[0]
                    ? { per_page: query.perPage }
                    : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const handleSearchChange = (value: string) => {
        setSelected([]);
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
        applyFilters({
            search: '',
            status: null,
            category: null,
            brand: null,
        });
    };

    // Clicking the column you are already on flips the direction; a new
    // column starts in its own default order.
    const applySort = (sort: ProductSort) => {
        const direction =
            filters.sort === sort
                ? filters.direction === 'asc'
                    ? 'desc'
                    : 'asc'
                : defaultDirection(sort);

        applyFilters({ sort, direction });
    };

    const openDetail = (product: Product) => {
        setProductToView(product);
        setDetailOpen(true);
    };

    // A selection only ever means rows that are still on screen, so paging
    // or filtering cannot act on products the user can no longer see.
    const visibleIds = products.data.map((product) => product.id);
    const selectedHere = selected.filter((id) => visibleIds.includes(id));
    const allSelected =
        visibleIds.length > 0 && selectedHere.length === visibleIds.length;

    const toggleAll = (checked: boolean) =>
        setSelected(checked ? visibleIds : []);

    const toggleOne = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...current, id]
                : current.filter((selectedId) => selectedId !== id),
        );

    const confirmDelete = (product: Product) => {
        setProductToDelete(product);
        setDeleteOpen(true);
    };

    return (
        <>
            <Head title="Products" />
            <div className="workspace-page">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="page-heading">
                        <h1 className="page-title">Products</h1>
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
                        className="workspace-panel space-y-3 p-5"
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

                <div className="workspace-table">
                    <div className="flex flex-col gap-2 border-b p-4 sm:flex-row sm:items-center">
                        <div className="relative w-full sm:max-w-xs">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    handleSearchChange(event.target.value)
                                }
                                placeholder="Search name, SKU, GTIN or brand"
                                aria-label="Search products by name, SKU, GTIN or brand"
                                data-test="product-search"
                                className="pl-9"
                            />
                        </div>

                        <Select
                            value={filters.status ?? ALL}
                            onValueChange={(value) =>
                                applyFilters({
                                    status:
                                        value === ALL
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
                                <SelectItem value={ALL}>
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

                        <Select
                            value={filters.category ?? ALL}
                            onValueChange={(value) =>
                                applyFilters({
                                    category: value === ALL ? null : value,
                                })
                            }
                        >
                            <SelectTrigger
                                className="w-full sm:w-56"
                                aria-label="Filter by category"
                                data-test="product-category-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    All categories
                                </SelectItem>
                                <SelectItem value={NONE}>
                                    Filed nowhere
                                </SelectItem>
                                {categoryOptions.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.brand ?? ALL}
                            onValueChange={(value) =>
                                applyFilters({
                                    brand: value === ALL ? null : value,
                                })
                            }
                        >
                            <SelectTrigger
                                className="w-full sm:w-44"
                                aria-label="Filter by brand"
                                data-test="product-brand-filter"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All brands</SelectItem>
                                <SelectItem value={NONE}>No brand</SelectItem>
                                {brandOptions.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {hasFilters ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                                data-test="clear-filters"
                            >
                                <X /> Clear
                            </Button>
                        ) : null}
                    </div>

                    {canManageProducts ? (
                        <ProductBulkBar
                            shopSlug={currentShop.slug}
                            selected={selectedHere}
                            onClear={() => setSelected([])}
                            statuses={statuses}
                            categoryOptions={categoryOptions}
                            brandOptions={brandOptions}
                            permissions={permissions}
                        />
                    ) : null}

                    {products.data.length > 0 ? (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    {canManageProducts ? (
                                        <TableHead className="w-10 pl-4">
                                            <Checkbox
                                                checked={allSelected}
                                                onCheckedChange={(checked) =>
                                                    toggleAll(checked === true)
                                                }
                                                aria-label="Select every product on this page"
                                                data-test="select-all"
                                            />
                                        </TableHead>
                                    ) : null}
                                    <TableHead className="pl-4">
                                        <SortButton
                                            sort="name"
                                            label="Product"
                                            filters={filters}
                                            onSort={applySort}
                                        />
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>
                                        <SortButton
                                            sort="stock"
                                            label="Inventory"
                                            filters={filters}
                                            onSort={applySort}
                                        />
                                    </TableHead>
                                    <TableHead className="text-right">
                                        <SortButton
                                            sort="price"
                                            label="Price"
                                            filters={filters}
                                            onSort={applySort}
                                            align="end"
                                        />
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        <SortButton
                                            sort="updated"
                                            label="Updated"
                                            filters={filters}
                                            onSort={applySort}
                                        />
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
                                        data-selected={selected.includes(
                                            product.id,
                                        )}
                                    >
                                        {canManageProducts ? (
                                            <TableCell className="w-10 pl-4">
                                                <Checkbox
                                                    checked={selected.includes(
                                                        product.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleOne(
                                                            product.id,
                                                            checked === true,
                                                        )
                                                    }
                                                    aria-label={`Select ${product.name}`}
                                                    data-test="select-product"
                                                />
                                            </TableCell>
                                        ) : null}
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
                                            Try a different search term, or
                                            loosen the filters.
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
                            <div className="text-muted-foreground flex flex-wrap items-center justify-center gap-3">
                                <p>
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

                                <Select
                                    value={String(filters.perPage)}
                                    onValueChange={(value) =>
                                        applyFilters({
                                            perPage: Number(value),
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-28"
                                        aria-label="Products per page"
                                        data-test="per-page"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {pageSizes.map((size) => (
                                            <SelectItem
                                                key={size}
                                                value={String(size)}
                                            >
                                                {size} / page
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {products.last_page > 1 ? (
                                <nav
                                    className="flex flex-wrap items-center justify-center gap-1"
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

// Text reads best ascending and figures descending, which is the order each
// is normally wanted in. Matches Product::defaultDirection() on the server.
function defaultDirection(sort: ProductSort): ProductFilters['direction'] {
    return sort === 'name' ? 'asc' : 'desc';
}

function SortButton({
    sort,
    label,
    filters,
    onSort,
    align = 'start',
}: {
    sort: ProductSort;
    label: string;
    filters: ProductFilters;
    onSort: (sort: ProductSort) => void;
    align?: 'start' | 'end';
}) {
    const active = filters.sort === sort;
    const Icon = !active
        ? ChevronsUpDown
        : filters.direction === 'asc'
          ? ArrowUp
          : ArrowDown;

    return (
        <button
            type="button"
            onClick={() => onSort(sort)}
            data-test={`sort-${sort}`}
            aria-label={`Sort by ${label.toLowerCase()}`}
            className={cn(
                'hover:text-foreground -mx-2 flex w-full items-center gap-1 rounded px-2 py-1',
                align === 'end' && 'justify-end',
                active && 'text-foreground',
            )}
        >
            {label}
            <Icon className={cn('size-3.5', !active && 'opacity-40')} />
        </button>
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

ProductIndex.layout = (props: { currentShop?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentShop ? dashboard(props.currentShop.slug) : '/',
        },
        {
            title: 'Products',
            href: props.currentShop
                ? productsIndex(props.currentShop.slug)
                : '',
        },
    ],
});
