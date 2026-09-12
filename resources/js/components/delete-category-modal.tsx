import { router } from '@inertiajs/react';
import { AlertTriangle, FolderTree, Package, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { cn } from '@/lib/utils';
import { deletionPreview, destroy } from '@/routes/categories';
import type { Category, ShopPermissions } from '@/types';

type Preview = {
    categoryCount: number;
    descendantCount: number;
    productCount: number;
    exclusiveProductCount: number;
    sharedProductCount: number;
    hasSyncedCategories: boolean;
    token: string;
    destinations: { id: number; path: string }[];
};

type Props = {
    category: Category;
    shopSlug: string;
    permissions: ShopPermissions;
    onClose: () => void;
};

export default function DeleteCategoryModal({
    category,
    shopSlug,
    permissions,
    onClose,
}: Props) {
    const [children, setChildren] = useState('keep');
    const [products, setProducts] = useState('keep');
    const [destination, setDestination] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewError, setPreviewError] = useState('');
    const [revision, setRevision] = useState(0);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        const controller = new AbortController();
        fetch(
            deletionPreview([shopSlug, category.id], { query: { children } })
                .url,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then(async (response) => {
                if (!response.ok) throw new Error('Preview unavailable');
                const result = (await response.json()) as Preview;
                if (!controller.signal.aborted) setPreview(result);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setPreviewError(
                        'Could not load the deletion preview. Try again before deleting.',
                    );
            });
        return () => controller.abort();
    }, [category.id, shopSlug, children, revision]);

    const refresh = () => {
        setPreview(null);
        setPreviewError('');
        setErrors({});
        setConfirmation('');
        setRevision((value) => value + 1);
    };

    const changeChildren = (value: string) => {
        setChildren(value);
        setDestination('');
        setPreview(null);
        setPreviewError('');
        setConfirmation('');
        setErrors({});
    };

    const ready =
        preview !== null &&
        !processing &&
        (products !== 'move' ||
            preview.destinations.some(
                (item) => String(item.id) === destination,
            )) &&
        (products !== 'delete' || confirmation === category.name);

    const submit = () => {
        if (!ready || !preview) return;
        setProcessing(true);
        setErrors({});
        router.visit(destroy([shopSlug, category.id]), {
            data: {
                children,
                products,
                ...(products === 'move' ? { destination_id: destination } : {}),
                ...(products === 'delete' ? { confirmation } : {}),
                preview_token: preview.token,
            },
            preserveScroll: true,
            onSuccess: onClose,
            onError: (result) => {
                setErrors(result);
                if (result.preview_token) setPreview(null);
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && !processing && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto rounded-2xl sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Delete category</DialogTitle>
                    <DialogDescription className="break-words">
                        Choose what happens to <strong>{category.name}</strong>{' '}
                        and its contents. Category deletion cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <fieldset disabled={processing} className="space-y-2">
                        <legend className="mb-2 text-sm font-semibold">
                            Child categories
                        </legend>
                        <Choice
                            name="category-children"
                            value="keep"
                            selected={children}
                            onChange={changeChildren}
                            title="Keep child categories"
                            description="Move direct children up one level. Their products stay where they are."
                        />
                        <Choice
                            name="category-children"
                            value="delete"
                            selected={children}
                            onChange={changeChildren}
                            title="Delete all child categories"
                            description="Remove this entire branch, including every nested category."
                        />
                    </fieldset>

                    <fieldset disabled={processing} className="space-y-2">
                        <legend className="mb-2 text-sm font-semibold">
                            Products in the categories being removed
                        </legend>
                        <Choice
                            name="category-products"
                            value="keep"
                            selected={products}
                            onChange={setProducts}
                            title="Keep products — recommended"
                            description="Only remove their links to deleted categories. Products with no remaining category become uncategorized."
                        />
                        {permissions.canUpdateProduct && (
                            <Choice
                                name="category-products"
                                value="move"
                                selected={products}
                                onChange={setProducts}
                                title="Move products to another category"
                                description="Add them to the destination and preserve any other category assignments."
                            />
                        )}
                        {permissions.canDeleteProduct && (
                            <Choice
                                name="category-products"
                                value="delete"
                                selected={products}
                                onChange={setProducts}
                                title="Delete products exclusive to these categories"
                                description="Permanently delete only products with no category outside this selection. Shared products are kept."
                                destructive
                            />
                        )}
                    </fieldset>

                    {products === 'move' && (
                        <div className="space-y-2">
                            <Label htmlFor="category-destination">
                                Destination category
                            </Label>
                            <Select
                                value={destination}
                                onValueChange={setDestination}
                                disabled={!preview || processing}
                            >
                                <SelectTrigger
                                    id="category-destination"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a destination" />
                                </SelectTrigger>
                                <SelectContent>
                                    {preview?.destinations.map((item) => (
                                        <SelectItem
                                            key={item.id}
                                            value={String(item.id)}
                                        >
                                            {item.path}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {preview?.destinations.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    No surviving category is available. Create a
                                    destination category first, or keep the
                                    products.
                                </p>
                            )}
                            <InputError message={errors.destination_id} />
                        </div>
                    )}

                    <div
                        className="bg-muted/40 space-y-2 rounded-xl border p-4 text-sm"
                        aria-live="polite"
                        data-test="category-delete-preview"
                    >
                        {preview ? (
                            <>
                                <p className="flex items-center gap-2 font-medium">
                                    <FolderTree className="size-4" />
                                    {preview.categoryCount}{' '}
                                    {preview.categoryCount === 1
                                        ? 'category'
                                        : 'categories'}{' '}
                                    will be deleted
                                </p>
                                <p className="flex items-center gap-2">
                                    <Package className="size-4" />
                                    {preview.productCount} unique products
                                    affected
                                </p>
                                <p className="text-muted-foreground">
                                    {products === 'delete'
                                        ? `${preview.exclusiveProductCount} ${preview.exclusiveProductCount === 1 ? 'product' : 'products'} will be permanently deleted. ${preview.sharedProductCount} shared ${preview.sharedProductCount === 1 ? 'product' : 'products'} will be kept.`
                                        : products === 'move'
                                          ? 'All affected products will be kept and assigned to your destination.'
                                          : `All products will be kept. ${preview.exclusiveProductCount} will become uncategorized.`}
                                </p>
                                {children === 'keep' &&
                                    preview.descendantCount > 0 && (
                                        <p className="text-muted-foreground">
                                            All {preview.descendantCount} child
                                            categories will be kept.
                                        </p>
                                    )}
                                {preview.hasSyncedCategories && (
                                    <p className="flex items-start gap-2 border-t pt-2 text-amber-700 dark:text-amber-400">
                                        <RefreshCw className="mt-0.5 size-4 shrink-0" />
                                        This is a local change. A future
                                        WooCommerce sync may recreate imported
                                        categories and products.
                                    </p>
                                )}
                            </>
                        ) : previewError || errors.preview_token ? (
                            <>
                                <p role="alert" className="text-destructive">
                                    {previewError || errors.preview_token}
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={refresh}
                                >
                                    Refresh preview
                                </Button>
                            </>
                        ) : (
                            <p className="flex items-center gap-2">
                                <Spinner /> Loading affected categories and
                                products…
                            </p>
                        )}
                    </div>

                    {products === 'delete' && (
                        <div className="space-y-2 rounded-xl border border-red-200 p-4 dark:border-red-900">
                            <p className="flex items-center gap-2 text-sm font-medium text-red-700 dark:text-red-400">
                                <AlertTriangle className="size-4" /> Product
                                deletion cannot be undone
                            </p>
                            <Label
                                htmlFor="category-delete-confirmation"
                                className="block text-sm leading-relaxed break-words"
                            >
                                Type “{category.name}” to confirm
                            </Label>
                            <Input
                                id="category-delete-confirmation"
                                autoComplete="off"
                                value={confirmation}
                                onChange={(event) =>
                                    setConfirmation(event.target.value)
                                }
                                disabled={processing}
                            />
                            <InputError message={errors.confirmation} />
                        </div>
                    )}
                    {Object.entries(errors)
                        .filter(
                            ([key]) =>
                                ![
                                    'destination_id',
                                    'confirmation',
                                    'preview_token',
                                ].includes(key),
                        )
                        .map(([key, message]) => (
                            <InputError key={key} message={message} />
                        ))}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            data-test="delete-term-confirm"
                            disabled={!ready}
                        >
                            {processing && <Spinner />}
                            {products === 'delete'
                                ? 'Delete category & products'
                                : 'Delete category'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Choice({
    name,
    value,
    selected,
    onChange,
    title,
    description,
    destructive = false,
}: {
    name: string;
    value: string;
    selected: string;
    onChange: (value: string) => void;
    title: string;
    description: string;
    destructive?: boolean;
}) {
    return (
        <label
            className={cn(
                'hover:bg-muted/40 has-focus-visible:ring-ring flex cursor-pointer items-start gap-3 rounded-xl border p-3 has-focus-visible:ring-2',
                selected === value && 'bg-muted/50 border-foreground/25',
            )}
        >
            <input
                type="radio"
                name={name}
                value={value}
                checked={selected === value}
                onChange={() => onChange(value)}
                className="accent-primary mt-1 size-4 shrink-0"
            />
            <span className="min-w-0">
                <span
                    className={cn(
                        'block text-sm font-medium',
                        destructive && 'text-red-700 dark:text-red-400',
                    )}
                >
                    {title}
                </span>
                <span className="text-muted-foreground mt-1 block text-xs leading-relaxed">
                    {description}
                </span>
            </span>
        </label>
    );
}
