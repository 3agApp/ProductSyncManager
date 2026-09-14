import { Head, router } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { useState } from 'react';
import ShopInvitationController from '@/actions/App/Http/Controllers/Shops/ShopInvitationController';
import { Button } from '@/components/ui/button';
import { index as invitationsIndex } from '@/routes/invitations';
import type { PendingInvitation } from '@/types';

type Props = {
    invitations: PendingInvitation[];
};

export default function Invitations({ invitations }: Props) {
    const [processingCode, setProcessingCode] = useState<string | null>(null);

    const accept = (invitation: PendingInvitation) => {
        router.visit(ShopInvitationController.accept(invitation), {
            onStart: () => setProcessingCode(invitation.code),
            onFinish: () => setProcessingCode(null),
        });
    };

    const decline = (invitation: PendingInvitation) => {
        router.visit(ShopInvitationController.decline(invitation), {
            preserveScroll: true,
            onStart: () => setProcessingCode(invitation.code),
            onFinish: () => setProcessingCode(null),
        });
    };

    return (
        <>
            <Head title="Invitations" />

            <div className="workspace-page">
                <div className="min-w-0 space-y-2">
                    <p className="text-muted-foreground text-xs font-medium tracking-[0.16em] uppercase">
                        Shops
                    </p>
                    <h1 className="text-3xl font-semibold tracking-tight">
                        Invitations
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Accept or decline the shops you have been invited to
                        join.
                    </p>
                </div>

                {invitations.length > 0 ? (
                    <div className="grid max-w-2xl gap-4">
                        {invitations.map((invitation) => (
                            <div
                                key={invitation.code}
                                data-test="pending-invitation-row"
                                className="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4"
                            >
                                <div className="min-w-0 space-y-1">
                                    <p className="font-medium">
                                        {invitation.shop.name}
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {invitation.inviterName} invited you to
                                        join as {invitation.roleLabel}.
                                        {invitation.expiresAtDiff
                                            ? ` Expires ${invitation.expiresAtDiff}.`
                                            : ''}
                                    </p>
                                </div>

                                <div className="flex gap-2">
                                    <Button
                                        variant="secondary"
                                        data-test="pending-invitation-decline"
                                        disabled={
                                            processingCode === invitation.code
                                        }
                                        onClick={() => decline(invitation)}
                                    >
                                        Decline
                                    </Button>

                                    <Button
                                        data-test="pending-invitation-accept"
                                        disabled={
                                            processingCode === invitation.code
                                        }
                                        onClick={() => accept(invitation)}
                                    >
                                        Accept
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div
                        className="text-muted-foreground flex max-w-2xl items-center gap-3 rounded-lg border border-dashed p-6 text-sm"
                        data-test="no-pending-invitations"
                    >
                        <Mail className="size-4" />
                        You have no pending invitations.
                    </div>
                )}
            </div>
        </>
    );
}

Invitations.layout = () => ({
    breadcrumbs: [{ title: 'Invitations', href: invitationsIndex() }],
});
