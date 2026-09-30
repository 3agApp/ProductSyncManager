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
import { update as updateMember } from '@/routes/shops/members';
import type { RoleOption, Shop, ShopMember } from '@/types';

type Props = {
    shop: Shop;
    member: ShopMember | null;
    role: RoleOption | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function ChangeMemberRoleModal({
    shop,
    member,
    role,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const changeRole = () => {
        if (!member || !role) {
            return;
        }

        router.visit(updateMember([shop.slug, member.id]), {
            data: { role: role.value },
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
                    <DialogTitle>Change role</DialogTitle>
                    <DialogDescription>
                        Make <strong>{member?.name}</strong> {article(role)}{' '}
                        <strong>{role?.label}</strong> of this shop? This
                        changes what they can see and do straight away.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>

                    <Button
                        data-test="member-role-confirm"
                        disabled={processing}
                        onClick={changeRole}
                    >
                        Change role
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function article(role: RoleOption | null): string {
    return role && /^[aeiou]/i.test(role.label) ? 'an' : 'a';
}
