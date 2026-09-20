import { Head, router } from '@inertiajs/react';
import { MailOpen } from 'lucide-react';
import { useState } from 'react';
import ShopInvitationController from '@/actions/App/Http/Controllers/Shops/ShopInvitationController';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/invitations';
import type { PendingInvitation } from '@/types';

type Props = {
    invitations: PendingInvitation[];
};

export default function InvitationsIndex({ invitations }: Props) {
    const [processingCode, setProcessingCode] = useState<string | null>(null);

    const respond = (
        invitation: PendingInvitation,
        action: 'accept' | 'decline',
    ) => {
        router.visit(ShopInvitationController[action](invitation), {
            onStart: () => setProcessingCode(invitation.code),
            onFinish: () => setProcessingCode(null),
        });
    };

    return (
        <>
            <Head title="Invitations" />

            <div className="workspace-page">
                <div className="page-heading">
                    <h1 className="page-title">Invitations</h1>
                    <p className="text-muted-foreground text-sm">
                        Accept or decline the shops you have been invited to
                        join.
                    </p>
                </div>

                {invitations.length > 0 ? (
                    <ul className="grid gap-3">
                        {invitations.map((invitation) => (
                            <li
                                key={invitation.code}
                                data-test="pending-invitation-row"
                                className="bg-card flex flex-wrap items-center justify-between gap-4 rounded-2xl border p-5 shadow-xs"
                            >
                                <div className="min-w-0 space-y-1">
                                    <p className="font-medium break-words">
                                        {invitation.shop.name}
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {invitation.inviterName} invited you to
                                        join as {invitation.roleLabel}.
                                        {invitation.expiresAt
                                            ? ` Expires ${new Date(invitation.expiresAt).toLocaleDateString()}.`
                                            : null}
                                    </p>
                                </div>

                                <div className="flex gap-2">
                                    <Button
                                        variant="secondary"
                                        data-test="pending-invitation-decline"
                                        disabled={
                                            processingCode === invitation.code
                                        }
                                        onClick={() =>
                                            respond(invitation, 'decline')
                                        }
                                    >
                                        Decline
                                    </Button>
                                    <Button
                                        data-test="pending-invitation-accept"
                                        disabled={
                                            processingCode === invitation.code
                                        }
                                        onClick={() =>
                                            respond(invitation, 'accept')
                                        }
                                    >
                                        Accept
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <div className="bg-card flex flex-col items-center justify-center gap-3 rounded-2xl border px-6 py-16 text-center shadow-xs">
                        <div className="bg-muted flex size-12 items-center justify-center rounded-full">
                            <MailOpen className="text-muted-foreground size-6" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                No pending invitations
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                When someone invites you to a shop, it will show
                                up here.
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

InvitationsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Invitations',
            href: index(),
        },
    ],
};
