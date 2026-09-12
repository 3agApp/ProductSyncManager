import { router } from '@inertiajs/react';
import type { Method } from '@inertiajs/core';
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

type Props = {
    title: string;
    description: string;
    confirmLabel: string;
    action: { url: string; method: Method } | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Confirm deleting a brand or a category.
 *
 * Both read the same way -- the term goes, the products stay -- so they
 * share one dialog and differ only in what it says.
 */
export default function DeleteCatalogTermModal({
    title,
    description,
    confirmLabel,
    action,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (!action) {
            return;
        }

        router.visit(action, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>

                    <Button
                        variant="destructive"
                        data-test="delete-term-confirm"
                        disabled={processing}
                        onClick={confirm}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
