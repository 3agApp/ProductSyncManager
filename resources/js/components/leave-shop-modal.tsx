import { router } from '@inertiajs/react';
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
import { leave as leaveShopAction } from '@/routes/shops';
import type { Shop } from '@/types';

type Props = {
    shop: Shop | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function LeaveShopModal({ shop, open, onOpenChange }: Props) {
    const [processing, setProcessing] = useState(false);

    const leaveShop = () => {
        if (!shop) {
            return;
        }

        router.visit(leaveShopAction(shop.slug), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Leave shop</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to leave{' '}
                        <strong>{shop?.name}</strong>?
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>

                    <Button
                        variant="destructive"
                        data-test="leave-shop-confirm"
                        disabled={processing}
                        onClick={leaveShop}
                    >
                        Leave shop
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
