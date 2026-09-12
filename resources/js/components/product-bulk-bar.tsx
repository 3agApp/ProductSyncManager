import { router } from '@inertiajs/react';
import { FolderMinus, FolderPlus, Tag, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { bulk } from '@/routes/products';
import type {
    FilterOption,
    ProductStatusOption,
    ShopPermissions,
} from '@/types';

type Props = {
    shopSlug: string;
    selected: number[];
    onClear: () => void;
    statuses: ProductStatusOption[];
    categoryOptions: FilterOption[];
    brandOptions: FilterOption[];
    permissions: ShopPermissions;
};

/**
 * Actions for the products currently ticked.
 *
 * The bar only appears once something is selected, so the table reads as
 * it did before anyone started picking rows.
 */
export default function ProductBulkBar({
    shopSlug,
    selected,
    onClear,
    statuses,
    categoryOptions,
    brandOptions,
    permissions,
}: Props) {
    const [processing, setProcessing] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    if (selected.length === 0) {
        return null;
    }

    const apply = (payload: Record<string, unknown>) => {
        router.post(
            bulk(shopSlug).url,
            { ids: selected, ...payload },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setConfirmingDelete(false);
                    onClear();
                },
            },
        );
    };

    const count = selected.length;
    const noun = count === 1 ? 'product' : 'products';

    return (
        <>
            <div
                data-test="bulk-bar"
                className="border-sidebar-border/70 dark:border-sidebar-border bg-muted/40 flex flex-wrap items-center gap-2 border-b p-3"
            >
                <span className="text-sm font-medium" data-test="bulk-count">
                    {count} {noun} selected
                </span>

                <div className="ml-auto flex flex-wrap items-center gap-2">
                    {permissions.canUpdateProduct ? (
                        <>
                            <DropdownMenu modal={false}>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        data-test="bulk-brand"
                                    >
                                        <Tag /> Set brand
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="max-h-80 w-56 overflow-y-auto"
                                >
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            apply({
                                                action: 'set-brand',
                                                brand_id: null,
                                            })
                                        }
                                    >
                                        No brand
                                    </DropdownMenuItem>
                                    {brandOptions.length > 0 ? (
                                        <DropdownMenuSeparator />
                                    ) : null}
                                    {brandOptions.map((option) => (
                                        <DropdownMenuItem
                                            key={option.value}
                                            onSelect={() =>
                                                apply({
                                                    action: 'set-brand',
                                                    brand_id: Number(
                                                        option.value,
                                                    ),
                                                })
                                            }
                                        >
                                            {option.label}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>

                            <CategoryMenu
                                label="Add to category"
                                icon={FolderPlus}
                                action="add-category"
                                options={categoryOptions}
                                onApply={apply}
                                disabled={processing}
                                testId="bulk-add-category"
                            />

                            <CategoryMenu
                                label="Remove from category"
                                icon={FolderMinus}
                                action="remove-category"
                                options={categoryOptions}
                                onApply={apply}
                                disabled={processing}
                                testId="bulk-remove-category"
                            />

                            <DropdownMenu modal={false}>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        data-test="bulk-status"
                                    >
                                        Set status
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {statuses.map((status) => (
                                        <DropdownMenuItem
                                            key={status.value}
                                            onSelect={() =>
                                                apply({
                                                    action: 'set-status',
                                                    status: status.value,
                                                })
                                            }
                                        >
                                            {status.label}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    ) : null}

                    {permissions.canDeleteProduct ? (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            data-test="bulk-delete"
                            onClick={() => setConfirmingDelete(true)}
                        >
                            <Trash2 /> Delete
                        </Button>
                    ) : null}

                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onClear}
                        data-test="bulk-clear"
                    >
                        <X /> Clear
                    </Button>
                </div>
            </div>

            <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Delete {count} {noun}
                        </DialogTitle>
                        <DialogDescription>
                            This cannot be undone. Products removed here are
                            gone from this app only; a later sync will bring
                            them back if the store still has them.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            data-test="bulk-delete-confirm"
                            onClick={() => apply({ action: 'delete' })}
                        >
                            Delete {noun}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function CategoryMenu({
    label,
    icon: Icon,
    action,
    options,
    onApply,
    disabled,
    testId,
}: {
    label: string;
    icon: typeof FolderPlus;
    action: string;
    options: FilterOption[];
    onApply: (payload: Record<string, unknown>) => void;
    disabled: boolean;
    testId: string;
}) {
    if (options.length === 0) {
        return null;
    }

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    data-test={testId}
                >
                    <Icon /> {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-80 w-64 overflow-y-auto"
            >
                <DropdownMenuLabel>{label}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {options.map((option) => (
                    <DropdownMenuItem
                        key={option.value}
                        onSelect={() =>
                            onApply({
                                action,
                                category_id: Number(option.value),
                            })
                        }
                    >
                        {option.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
