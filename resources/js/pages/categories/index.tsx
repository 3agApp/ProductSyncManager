import { Form, Head, usePage } from '@inertiajs/react';
import {
    CornerDownRight,
    FolderTree,
    Pencil,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
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
import {
    destroy,
    index as categoriesIndex,
    store,
    update,
} from '@/routes/categories';
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
                                {categories.map((category) => (
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
                                                    {category.depth > 0 ? (
                                                        <CornerDownRight className="text-muted-foreground size-3.5 shrink-0" />
                                                    ) : null}
                                                    <span className="font-medium">
                                                        {category.name}
                                                    </span>
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {category.productCount.toLocaleString()}
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
