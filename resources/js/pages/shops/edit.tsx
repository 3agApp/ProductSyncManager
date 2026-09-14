import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Mail, RefreshCw, Store, UserPlus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import CancelInvitationModal from '@/components/cancel-invitation-modal';
import DeleteShopModal from '@/components/delete-shop-modal';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import InviteMemberModal from '@/components/invite-member-modal';
import RemoveMemberModal from '@/components/remove-member-modal';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/hooks/use-initials';
import { edit, index, update } from '@/routes/shops';
import { update as updateMember } from '@/routes/shops/members';
import { edit as editNalda } from '@/routes/shops/nalda';
import { edit as editSync } from '@/routes/shops/sync';
import type {
    RoleOption,
    Shop,
    ShopInvitation,
    ShopMember,
    ShopPermissions,
} from '@/types';

type Props = {
    shop: Shop;
    members: ShopMember[];
    invitations: ShopInvitation[];
    permissions: ShopPermissions;
    availableRoles: RoleOption[];
};

export default function ShopEdit({
    shop,
    members,
    invitations,
    permissions,
    availableRoles,
}: Props) {
    const getInitials = useInitials();
    const { shopTypes } = usePage().props;

    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [removeMemberDialogOpen, setRemoveMemberDialogOpen] = useState(false);
    const [memberToRemove, setMemberToRemove] = useState<ShopMember | null>(
        null,
    );
    const [cancelInvitationDialogOpen, setCancelInvitationDialogOpen] =
        useState(false);
    const [invitationToCancel, setInvitationToCancel] =
        useState<ShopInvitation | null>(null);

    const pageTitle = useMemo(
        () =>
            permissions.canUpdateShop
                ? `Edit ${shop.name}`
                : `View ${shop.name}`,
        [permissions.canUpdateShop, shop.name],
    );

    const updateMemberRole = (member: ShopMember, newRole: string) => {
        router.visit(updateMember([shop.slug, member.id]), {
            data: { role: newRole },
            preserveScroll: true,
        });
    };

    const confirmRemoveMember = (member: ShopMember) => {
        setMemberToRemove(member);
        setRemoveMemberDialogOpen(true);
    };

    const confirmCancelInvitation = (invitation: ShopInvitation) => {
        setInvitationToCancel(invitation);
        setCancelInvitationDialogOpen(true);
    };

    return (
        <>
            <Head title={pageTitle} />

            <h1 className="sr-only">{pageTitle}</h1>

            <div className="flex flex-col space-y-10">
                <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                    {permissions.canUpdateShop ? (
                        <>
                            <Heading
                                variant="small"
                                title="Shop settings"
                                description="Update your shop name and settings"
                            />

                            <Form
                                {...update.form(shop.slug)}
                                className="space-y-6"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="name">
                                                Shop name
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                data-test="shop-name-input"
                                                defaultValue={shop.name}
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="type">
                                                Shop type
                                            </Label>
                                            <Select
                                                name="type"
                                                defaultValue={
                                                    shop.type ?? 'wordpress'
                                                }
                                            >
                                                <SelectTrigger
                                                    id="type"
                                                    data-test="shop-type-input"
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {shopTypes.map((option) => (
                                                        <SelectItem
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.type} />
                                        </div>

                                        <div className="flex flex-wrap items-center gap-4">
                                            <Button
                                                type="submit"
                                                data-test="shop-save-button"
                                                disabled={processing}
                                            >
                                                Save
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </>
                    ) : (
                        <>
                            <Heading variant="small" title={shop.name} />
                        </>
                    )}
                </div>

                {permissions.canRunSync ? (
                    <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <Heading
                                variant="small"
                                title="Product sync"
                                description="Connect this shop to a WooCommerce store and pull its catalog."
                            />

                            <Button variant="secondary" asChild>
                                <Link
                                    href={editSync(shop.slug)}
                                    data-test="shop-sync-link"
                                >
                                    <RefreshCw /> Sync settings
                                </Link>
                            </Button>
                        </div>
                    </div>
                ) : null}

                {permissions.canRunSync ? (
                    <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <Heading
                                variant="small"
                                title="Nalda marketplace"
                                description="List this shop's products on Nalda through its SFTP product upload."
                            />

                            <Button variant="secondary" asChild>
                                <Link
                                    href={editNalda(shop.slug)}
                                    data-test="shop-nalda-link"
                                >
                                    <Store /> Nalda settings
                                </Link>
                            </Button>
                        </div>
                    </div>
                ) : null}

                <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Heading
                            variant="small"
                            title="Shop members"
                            description={
                                permissions.canCreateInvitation
                                    ? 'Manage who belongs to this shop'
                                    : ''
                            }
                        />

                        {permissions.canCreateInvitation ? (
                            <Button
                                data-test="invite-member-button"
                                onClick={() => setInviteDialogOpen(true)}
                            >
                                <UserPlus /> Invite member
                            </Button>
                        ) : null}
                    </div>

                    <div className="space-y-3">
                        {members.map((member) => (
                            <div
                                key={member.id}
                                data-test="member-row"
                                className="flex items-center justify-between rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center gap-4">
                                    <Avatar className="h-10 w-10">
                                        {member.avatar ? (
                                            <AvatarImage
                                                src={member.avatar}
                                                alt={member.name}
                                            />
                                        ) : null}
                                        <AvatarFallback>
                                            {getInitials(member.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div>
                                        <div className="font-medium">
                                            {member.name}
                                        </div>
                                        <div className="text-muted-foreground text-sm">
                                            {member.email}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    {member.role !== 'owner' &&
                                    permissions.canUpdateMember ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    data-test="member-role-trigger"
                                                >
                                                    {member.role_label}
                                                    <ChevronDown className="ml-2 h-4 w-4 opacity-50" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent>
                                                {availableRoles.map((role) => (
                                                    <DropdownMenuItem
                                                        key={role.value}
                                                        data-test="member-role-option"
                                                        onSelect={() =>
                                                            updateMemberRole(
                                                                member,
                                                                role.value,
                                                            )
                                                        }
                                                    >
                                                        {role.label}
                                                    </DropdownMenuItem>
                                                ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : (
                                        <Badge variant="secondary">
                                            {member.role_label}
                                        </Badge>
                                    )}

                                    {member.role !== 'owner' &&
                                    permissions.canRemoveMember ? (
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="member-remove-button"
                                                        onClick={() =>
                                                            confirmRemoveMember(
                                                                member,
                                                            )
                                                        }
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>Remove member</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    ) : null}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {invitations.length > 0 ? (
                    <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                        <Heading
                            variant="small"
                            title="Pending invitations"
                            description="Invitations that haven't been accepted yet"
                        />

                        <div className="space-y-3">
                            {invitations.map((invitation) => (
                                <div
                                    key={invitation.code}
                                    data-test="invitation-row"
                                    className="flex items-center justify-between rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-4">
                                        <div className="bg-muted flex h-10 w-10 items-center justify-center rounded-full">
                                            <Mail className="text-muted-foreground h-5 w-5" />
                                        </div>
                                        <div>
                                            <div className="font-medium">
                                                {invitation.email}
                                            </div>
                                            <div className="text-muted-foreground text-sm">
                                                {invitation.role_label}
                                            </div>
                                        </div>
                                    </div>

                                    {permissions.canCancelInvitation ? (
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="invitation-cancel-button"
                                                        onClick={() =>
                                                            confirmCancelInvitation(
                                                                invitation,
                                                            )
                                                        }
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>Cancel invitation</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                {permissions.canDeleteShop && !shop.isPersonal ? (
                    <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                        <Heading
                            variant="small"
                            title="Delete shop"
                            description="Permanently delete your shop"
                        />
                        <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                            <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                                <p className="font-medium">Warning</p>
                                <p className="text-sm">
                                    Please proceed with caution, this cannot be
                                    undone.
                                </p>
                            </div>
                            <Button
                                variant="destructive"
                                data-test="delete-shop-button"
                                onClick={() => setDeleteDialogOpen(true)}
                            >
                                Delete shop
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>

            {permissions.canCreateInvitation ? (
                <InviteMemberModal
                    shop={shop}
                    availableRoles={availableRoles}
                    open={inviteDialogOpen}
                    onOpenChange={setInviteDialogOpen}
                />
            ) : null}

            <RemoveMemberModal
                shop={shop}
                member={memberToRemove}
                open={removeMemberDialogOpen}
                onOpenChange={setRemoveMemberDialogOpen}
            />

            <CancelInvitationModal
                shop={shop}
                invitation={invitationToCancel}
                open={cancelInvitationDialogOpen}
                onOpenChange={setCancelInvitationDialogOpen}
            />

            {permissions.canDeleteShop && !shop.isPersonal ? (
                <DeleteShopModal
                    shop={shop}
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                />
            ) : null}
        </>
    );
}

ShopEdit.layout = (props: { shop: { name: string; slug: string } }) => ({
    breadcrumbs: [
        {
            title: 'Shops',
            href: index(),
        },
        {
            title: props.shop.name,
            href: edit(props.shop.slug),
        },
    ],
});
