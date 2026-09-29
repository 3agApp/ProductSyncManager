import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Tags, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import DeleteCatalogTermModal from '@/components/delete-catalog-term-modal';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { dashboard } from '@/routes';
import { destroy, index as brandsIndex, store, update } from '@/routes/brands';
import { index as productsIndex } from '@/routes/products';
import type { Brand, ShopPermissions } from '@/types';
import type { NaldaListing, NaldaListingOption } from '@/types/nalda';
import type { TemuListing, TemuListingOption } from '@/types/temu';

type BrandRow = Brand & {
    naldaListing: NaldaListing;
    temuListing: TemuListing;
};

type ListingField = 'nalda_listing' | 'temu_listing';

type Props = {
    brands: BrandRow[];
    permissions: ShopPermissions;
    hasNalda: boolean;
    hasTemu: boolean;
    naldaListings: NaldaListingOption[];
    temuListings: TemuListingOption[];
};

export default function BrandIndex({
    brands,
    permissions,
    hasNalda,
    hasTemu,
    naldaListings,
    temuListings,
}: Props) {
    const { currentShop } = usePage().props;
    const [editing, setEditing] = useState<BrandRow | null>(null);
    const [toDelete, setToDelete] = useState<BrandRow | null>(null);

    if (!currentShop) {
        return null;
    }

    return (
        <>
            <Head title="Brands" />

            <div className="workspace-page">
                <div className="page-heading">
                    <h1 className="page-title">Brands</h1>
                    <p className="text-muted-foreground text-sm">
                        The names products in {currentShop.name} are sold under.
                        A product carries one.
                    </p>
                    {hasNalda || hasTemu ? (
                        <p className="text-muted-foreground text-sm">
                            A brand can put all its products on a marketplace or
                            keep them all off, whatever the shop default says. A
                            product set to always include or exclude still has
                            the last word.
                        </p>
                    ) : null}
                </div>

                {permissions.canCreateBrand ? (
                    <Form
                        {...store.form(currentShop.slug)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="workspace-panel p-5 sm:p-6"
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="brand-name">
                                        Add a brand
                                    </Label>
                                    <Input
                                        id="brand-name"
                                        name="name"
                                        data-test="brand-name"
                                        placeholder="Acme"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="brand-submit"
                                >
                                    <Plus /> Add brand
                                </Button>
                            </div>
                        )}
                    </Form>
                ) : null}

                <div className="workspace-table">
                    {brands.length > 0 ? (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4">
                                        Brand
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Products
                                    </TableHead>
                                    {hasTemu ? (
                                        <TableHead className="w-52">
                                            Temu
                                        </TableHead>
                                    ) : null}
                                    {hasNalda ? (
                                        <TableHead className="w-52">
                                            Nalda
                                        </TableHead>
                                    ) : null}
                                    <TableHead className="w-32 pr-4">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {brands.map((brand) => (
                                    <TableRow
                                        key={brand.id}
                                        data-test="brand-row"
                                    >
                                        <TableCell className="pl-4">
                                            {editing?.id === brand.id ? (
                                                <Form
                                                    {...update.form([
                                                        currentShop.slug,
                                                        brand.id,
                                                    ])}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    onSuccess={() =>
                                                        setEditing(null)
                                                    }
                                                    className="flex items-center gap-2"
                                                >
                                                    {({
                                                        errors,
                                                        processing,
                                                    }) => (
                                                        <>
                                                            <div className="grid gap-2">
                                                                <Input
                                                                    name="name"
                                                                    defaultValue={
                                                                        brand.name
                                                                    }
                                                                    data-test="brand-rename"
                                                                    aria-label={`Rename ${brand.name}`}
                                                                    className="h-8 w-48"
                                                                    required
                                                                />
                                                                <InputError
                                                                    message={
                                                                        errors.name
                                                                    }
                                                                />
                                                            </div>
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Save
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setEditing(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                <X />
                                                                <span className="sr-only">
                                                                    Cancel
                                                                </span>
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                            ) : (
                                                <span className="font-medium">
                                                    {brand.name}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <ProductCountLink
                                                count={brand.productCount}
                                                href={`${productsIndex(currentShop.slug).url}?brand=${brand.id}`}
                                                label={brand.name}
                                            />
                                        </TableCell>
                                        {hasTemu ? (
                                            <TableCell>
                                                <ListingSelect
                                                    shop={currentShop.slug}
                                                    brand={brand}
                                                    field="temu_listing"
                                                    marketplace="Temu"
                                                    value={brand.temuListing}
                                                    options={temuListings}
                                                    canUpdate={
                                                        permissions.canUpdateBrand
                                                    }
                                                />
                                            </TableCell>
                                        ) : null}
                                        {hasNalda ? (
                                            <TableCell>
                                                <ListingSelect
                                                    shop={currentShop.slug}
                                                    brand={brand}
                                                    field="nalda_listing"
                                                    marketplace="Nalda"
                                                    value={brand.naldaListing}
                                                    options={naldaListings}
                                                    canUpdate={
                                                        permissions.canUpdateBrand
                                                    }
                                                />
                                            </TableCell>
                                        ) : null}
                                        <TableCell className="pr-4 text-right">
                                            <div className="flex justify-end gap-1">
                                                {permissions.canUpdateBrand &&
                                                editing?.id !== brand.id ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        data-test="brand-edit"
                                                        onClick={() =>
                                                            setEditing(brand)
                                                        }
                                                    >
                                                        <Pencil />
                                                        <span className="sr-only">
                                                            Rename {brand.name}
                                                        </span>
                                                    </Button>
                                                ) : null}

                                                {permissions.canDeleteBrand ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        data-test="brand-delete"
                                                        onClick={() =>
                                                            setToDelete(brand)
                                                        }
                                                    >
                                                        <Trash2 />
                                                        <span className="sr-only">
                                                            Delete {brand.name}
                                                        </span>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    ) : (
                        <div className="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                            <div className="bg-muted flex size-12 items-center justify-center rounded-full">
                                <Tags className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">No brands yet</h2>
                                <p className="text-muted-foreground text-sm">
                                    Brands appear here as you add them, or the
                                    first time a sync brings them in.
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <DeleteCatalogTermModal
                open={toDelete !== null}
                onOpenChange={(open) => !open && setToDelete(null)}
                action={
                    toDelete ? destroy([currentShop.slug, toDelete.id]) : null
                }
                title="Delete brand"
                confirmLabel="Delete brand"
                description={
                    toDelete
                        ? `${toDelete.name} will be removed. Its ${toDelete.productCount} product${toDelete.productCount === 1 ? '' : 's'} stay in the catalog, without a brand.`
                        : ''
                }
            />
        </>
    );
}

function ProductCountLink({
    count,
    href,
    label,
}: {
    count: number;
    href: string;
    label: string;
}) {
    if (count === 0) {
        return <span className="text-muted-foreground">0</span>;
    }

    return (
        <Link
            href={href}
            data-test="product-count-link"
            className="hover:text-primary underline-offset-4 hover:underline"
            aria-label={`Show the ${count} products in ${label}`}
        >
            {count.toLocaleString()}
        </Link>
    );
}

BrandIndex.layout = (props: { currentShop?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentShop ? dashboard(props.currentShop.slug) : '/',
        },
        {
            title: 'Brands',
            href: props.currentShop ? brandsIndex(props.currentShop.slug) : '',
        },
    ],
});

/**
 * A brand's choice for one marketplace, saved as soon as it changes.
 */
function ListingSelect({
    shop,
    brand,
    field,
    marketplace,
    value,
    options,
    canUpdate,
}: {
    shop: string;
    brand: BrandRow;
    field: ListingField;
    marketplace: string;
    value: string;
    options: { value: string; label: string }[];
    canUpdate: boolean;
}) {
    if (!canUpdate) {
        return (
            <span className="text-muted-foreground text-sm">
                {options.find((option) => option.value === value)?.label}
            </span>
        );
    }

    return (
        <Select
            value={value}
            onValueChange={(listing) =>
                router.patch(
                    update.url([shop, brand.id]),
                    { [field]: listing },
                    { preserveScroll: true },
                )
            }
        >
            <SelectTrigger
                size="sm"
                className="w-full"
                data-test={`brand-${field.replace('_', '-')}`}
                aria-label={`${marketplace} listing for ${brand.name}`}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
