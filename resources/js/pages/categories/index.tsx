import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    ChevronsDownUp,
    CornerDownRight,
    FolderTree,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import DeleteCatalogTermModal from '@/components/delete-catalog-term-modal';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    destroy,
    index as categoriesIndex,
    store,
    update,
} from '@/routes/categories';
import { index as productsIndex } from '@/routes/products';
import type { Category, ShopPermissions } from '@/types';

type Props = {
    categories: Category[];
    permissions: ShopPermissions;
};

const NO_PARENT = 'none';

export default function CategoryIndex({ categories, permissions }: Props) {
    const { currentShop } = usePage().props;
    const [editing, setEditing] = useState<Category | null>(null);
    const [toDelete, setToDelete] = useState<Category | null>(null);
    const [filter, setFilter] = useState('');
    const [collapsed, setCollapsed] = useState<number[]>([]);

    if (!currentShop) {
        return null;
    }

    // A category cannot be filed under itself or under anything below it,
    // so those are left out of the parent list while editing.
    const parentOptions = (category: Category) =>
        categories.filter(
            (option) =>
                option.id !== category.id &&
                !option.path.startsWith(`${category.path} > `),
        );

    const term = filter.trim().toLowerCase();

    // A search reveals matches wherever they sit, so a filtered tree
    // ignores what is collapsed; without one, anything inside a collapsed
    // branch is folded away.
    const matches = (category: Category) =>
        term === '' || category.path.toLowerCase().includes(term);

    const hidden = (category: Category) => {
        if (term !== '') {
            return !matches(category) && !descendantMatches(category);
        }

        return collapsed.some(
            (id) =>
                id !== category.id &&
                category.path.startsWith(`${pathOf(id)} > `),
        );
    };

    const pathOf = (id: number) =>
        categories.find((category) => category.id === id)?.path ?? '';

    const descendantMatches = (category: Category) =>
        categories.some(
            (other) =>
                other.path.startsWith(`${category.path} > `) && matches(other),
        );

    const visible = categories.filter((category) => !hidden(category));

    const toggle = (category: Category) =>
        setCollapsed((current) =>
            current.includes(category.id)
                ? current.filter((id) => id !== category.id)
                : [...current, category.id],
        );

    const allCollapsed = categories
        .filter((category) => category.childCount > 0)
        .every((category) => collapsed.includes(category.id));

    const toggleAll = () =>
        setCollapsed(
            allCollapsed
                ? []
                : categories
                      .filter((category) => category.childCount > 0)
                      .map((category) => category.id),
        );

    return (
        <>
            <Head title="Categories" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-0.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Categories
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        How the {currentShop.name} catalog is filed. A category
                        can sit under another, and a product can be in several.
                    </p>
                </div>

                {permissions.canCreateCategory ? (
                    <Form
                        {...store.form(currentShop.slug)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
                    >
                        {({ errors, processing }) => (
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="category-name">
                                        Add a category
                                    </Label>
                                    <Input
                                        id="category-name"
                                        name="name"
                                        data-test="category-name"
                                        placeholder="Totes"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2 sm:w-64">
                                    <Label htmlFor="category-parent">
                                        Sits under
                                    </Label>
                                    <Select
                                        name="parent_id"
                                        defaultValue={NO_PARENT}
                                    >
                                        <SelectTrigger
                                            id="category-parent"
                                            data-test="category-parent"
                                        >
                                            <SelectValue placeholder="Nothing — top level" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NO_PARENT}>
                                                Nothing — top level
                                            </SelectItem>
                                            {categories.map((category) => (
                                                <SelectItem
                                                    key={category.id}
                                                    value={String(category.id)}
                                                >
                                                    {category.path}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.parent_id} />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="category-submit"
                                >
                                    <Plus /> Add category
                                </Button>
                            </div>
                        )}
                    </Form>
                ) : null}

                <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col rounded-xl border">
                    {categories.length > 0 ? (
                        <div className="flex flex-col gap-2 border-b p-4 sm:flex-row sm:items-center">
                            <div className="relative w-full sm:max-w-xs">
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    type="search"
                                    value={filter}
                                    onChange={(event) =>
                                        setFilter(event.target.value)
                                    }
                                    placeholder="Filter categories"
                                    aria-label="Filter categories"
                                    data-test="category-filter"
                                    className="pl-9"
                                />
                            </div>

                            <Button
                                variant="outline"
                                size="sm"
                                onClick={toggleAll}
                                data-test="toggle-all-categories"
                            >
                                {allCollapsed ? (
                                    <>
                                        <ChevronsDownUp className="rotate-180" />{' '}
                                        Expand all
                                    </>
                                ) : (
                                    <>
                                        <ChevronsDownUp /> Collapse all
                                    </>
                                )}
                            </Button>

                            <span
                                className="text-muted-foreground text-sm sm:ml-auto"
                                data-test="category-count"
                            >
                                {visible.length === categories.length
                                    ? `${categories.length} categories`
                                    : `${visible.length} of ${categories.length} categories`}
                            </span>
                        </div>
                    ) : null}

                    {visible.length > 0 ? (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4">
                                        Category
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Products
                                    </TableHead>
                                    <TableHead className="w-32 pr-4">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.map((category) => (
                                    <TableRow
                                        key={category.id}
                                        data-test="category-row"
                                    >
                                        <TableCell className="pl-4">
                                            {editing?.id === category.id ? (
                                                <Form
                                                    {...update.form([
                                                        currentShop.slug,
                                                        category.id,
                                                    ])}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    onSuccess={() =>
                                                        setEditing(null)
                                                    }
                                                    className="flex flex-wrap items-start gap-2"
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
                                                                        category.name
                                                                    }
                                                                    data-test="category-rename"
                                                                    aria-label={`Rename ${category.name}`}
                                                                    className="h-8 w-44"
                                                                    required
                                                                />
                                                                <InputError
                                                                    message={
                                                                        errors.name
                                                                    }
                                                                />
                                                            </div>

                                                            <div className="grid gap-2">
                                                                <Select
                                                                    name="parent_id"
                                                                    defaultValue={
                                                                        category.parentId
                                                                            ? String(
                                                                                  category.parentId,
                                                                              )
                                                                            : NO_PARENT
                                                                    }
                                                                >
                                                                    <SelectTrigger
                                                                        className="h-8 w-52"
                                                                        data-test="category-reparent"
                                                                        aria-label={`Move ${category.name}`}
                                                                    >
                                                                        <SelectValue placeholder="Nothing — top level" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem
                                                                            value={
                                                                                NO_PARENT
                                                                            }
                                                                        >
                                                                            Nothing
                                                                            —
                                                                            top
                                                                            level
                                                                        </SelectItem>
                                                                        {parentOptions(
                                                                            category,
                                                                        ).map(
                                                                            (
                                                                                option,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        option.id
                                                                                    }
                                                                                    value={String(
                                                                                        option.id,
                                                                                    )}
                                                                                >
                                                                                    {
                                                                                        option.path
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectContent>
                                                                </Select>
                                                                <InputError
                                                                    message={
                                                                        errors.parent_id
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
                                                <div
                                                    className="flex items-center gap-1.5"
                                                    style={{
                                                        paddingLeft: `${category.depth * 1.25}rem`,
                                                    }}
                                                >
                                                    {category.childCount > 0 ? (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                toggle(category)
                                                            }
                                                            data-test="category-toggle"
                                                            aria-expanded={
                                                                !collapsed.includes(
                                                                    category.id,
                                                                )
                                                            }
                                                            aria-label={`${collapsed.includes(category.id) ? 'Expand' : 'Collapse'} ${category.name}`}
                                                            className="hover:bg-muted -ml-1 rounded p-0.5"
                                                        >
                                                            <ChevronRight
                                                                className={cn(
                                                                    'text-muted-foreground size-4 transition-transform',
                                                                    !collapsed.includes(
                                                                        category.id,
                                                                    ) &&
                                                                        'rotate-90',
                                                                )}
                                                            />
                                                        </button>
                                                    ) : category.depth > 0 ? (
                                                        <CornerDownRight className="text-muted-foreground size-3.5 shrink-0" />
                                                    ) : null}

                                                    <span className="font-medium">
                                                        {category.name}
                                                    </span>

                                                    {category.childCount > 0 &&
                                                    collapsed.includes(
                                                        category.id,
                                                    ) ? (
                                                        <span className="text-muted-foreground text-xs">
                                                            (
                                                            {
                                                                category.childCount
                                                            }
                                                            )
                                                        </span>
                                                    ) : null}

                                                    {category.productCount ===
                                                        0 &&
                                                    category.childCount ===
                                                        0 ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="text-muted-foreground ml-1 font-normal"
                                                            data-test="category-empty"
                                                        >
                                                            empty
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <ProductCountLink
                                                count={category.productCount}
                                                href={`${productsIndex(currentShop.slug).url}?category=${category.id}`}
                                                label={category.path}
                                            />
                                        </TableCell>
                                        <TableCell className="pr-4 text-right">
                                            <div className="flex justify-end gap-1">
                                                {permissions.canUpdateCategory &&
                                                editing?.id !== category.id ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        data-test="category-edit"
                                                        onClick={() =>
                                                            setEditing(category)
                                                        }
                                                    >
                                                        <Pencil />
                                                        <span className="sr-only">
                                                            Edit {category.name}
                                                        </span>
                                                    </Button>
                                                ) : null}

                                                {permissions.canDeleteCategory ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        data-test="category-delete"
                                                        onClick={() =>
                                                            setToDelete(
                                                                category,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                        <span className="sr-only">
                                                            Delete{' '}
                                                            {category.name}
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
                                <FolderTree className="text-muted-foreground size-6" />
                            </div>
                            <div className="space-y-1">
                                <h2 className="font-medium">
                                    No categories yet
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    Categories appear here as you add them, or
                                    the first time a sync brings the store's
                                    tree in.
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
                title="Delete category"
                confirmLabel="Delete category"
                description={
                    toDelete
                        ? `${toDelete.name} will be removed. Its ${toDelete.productCount} product${toDelete.productCount === 1 ? '' : 's'} stay in the catalog${toDelete.childCount > 0 ? `, and its ${toDelete.childCount} subcategor${toDelete.childCount === 1 ? 'y moves' : 'ies move'} up a level` : ''}.`
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

CategoryIndex.layout = (props: { currentShop?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentShop ? dashboard(props.currentShop.slug) : '/',
        },
        {
            title: 'Categories',
            href: props.currentShop
                ? categoriesIndex(props.currentShop.slug)
                : '',
        },
    ],
});
