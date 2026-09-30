import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Mail,
    RefreshCw,
    Send,
    ShoppingBag,
    Store,
    UserPlus,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import AttentionList from '@/components/attention-list';
import CancelInvitationModal from '@/components/cancel-invitation-modal';
import ChangeMemberRoleModal from '@/components/change-member-role-modal';
import DangerZone from '@/components/danger-zone';
import DeleteShopModal from '@/components/delete-shop-modal';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import InviteMemberModal from '@/components/invite-member-modal';
import RemoveMemberModal from '@/components/remove-member-modal';
import SaveButton from '@/components/save-button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
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
import { cn } from '@/lib/utils';
import { edit, index, update } from '@/routes/shops';
import { resend as resendInvitation } from '@/routes/shops/invitations';
import { edit as editNalda } from '@/routes/shops/nalda';
import { edit as editSync } from '@/routes/shops/sync';
import { edit as editTemu } from '@/routes/shops/temu';
import type {
    RoleOption,
    Shop,
    ShopAttentionItem,
    ShopIntegration,
    ShopInvitation,
    ShopMember,
    ShopPermissions,
} from '@/types';

type Props = {
    shop: Shop & { pricesIncludeTax: boolean; taxRate: string };
    members: ShopMember[];
    invitations: ShopInvitation[];
    integrations: ShopIntegration[];
    attention: ShopAttentionItem[];
    permissions: ShopPermissions;
    availableRoles: RoleOption[];
};

const integrationDetails: Record<
    ShopIntegration['key'],
    { title: string; description: string; icon: LucideIcon }
> = {
    sync: {
        title: 'WooCommerce sync',
        description: 'Pull the catalog from a WooCommerce store.',
        icon: RefreshCw,
    },
    nalda: {
        title: 'Nalda marketplace',
        description: 'List products on Nalda through its SFTP upload.',
        icon: Store,
    },
    temu: {
        title: 'Temu marketplace',
        description: 'List products on Temu through its self-developed app.',
        icon: ShoppingBag,
    },
};

const statusStyles: Record<ShopIntegration['status'], string> = {
    connected:
        'border-emerald-600/30 text-emerald-700 dark:border-emerald-400/30 dark:text-emerald-400',
    running:
        'border-sky-600/30 text-sky-700 dark:border-sky-400/30 dark:text-sky-400',
    failing:
        'border-red-600/30 text-red-700 dark:border-red-400/30 dark:text-red-400',
    not_configured: 'text-muted-foreground',
};

export default function ShopEdit({
    shop,
    members,
    invitations,
    integrations,
    attention,
    permissions,
    availableRoles,
}: Props) {
    const getInitials = useInitials();
    const { shopTypes, auth } = usePage().props;

    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [removeMemberDialogOpen, setRemoveMemberDialogOpen] = useState(false);
    const [memberToRemove, setMemberToRemove] = useState<ShopMember | null>(
        null,
    );
    const [roleChange, setRoleChange] = useState<{
        member: ShopMember;
        role: RoleOption;
    } | null>(null);
    const [roleDialogOpen, setRoleDialogOpen] = useState(false);
    const [cancelInvitationDialogOpen, setCancelInvitationDialogOpen] =
        useState(false);
    const [invitationToCancel, setInvitationToCancel] =
        useState<ShopInvitation | null>(null);
    const [resending, setResending] = useState<string | null>(null);

    const pageTitle = useMemo(
        () =>
            permissions.canUpdateShop
                ? `Edit ${shop.name}`
                : `View ${shop.name}`,
        [permissions.canUpdateShop, shop.name],
    );

    const integrationHref = (key: ShopIntegration['key']) =>
        ({
            sync: editSync(shop.slug).url,
            nalda: editNalda(shop.slug).url,
            temu: editTemu(shop.slug).url,
        })[key];

    const attentionItems = attention.map((item) => ({
        ...item,
        href:
            item.target === 'invitations'
                ? '#invitations'
                : integrationHref(item.target),
    }));

    const currentMember = members.find((member) => member.id === auth.user.id);

    const confirmRoleChange = (member: ShopMember, value: string) => {
        const role = availableRoles.find((option) => option.value === value);

        if (!role || role.value === member.role) {
            return;
        }

        setRoleChange({ member, role });
        setRoleDialogOpen(true);
    };

    const confirmRemoveMember = (member: ShopMember) => {
        setMemberToRemove(member);
        setRemoveMemberDialogOpen(true);
    };

    const confirmCancelInvitation = (invitation: ShopInvitation) => {
        setInvitationToCancel(invitation);
        setCancelInvitationDialogOpen(true);
    };

    const resend = (invitation: ShopInvitation) => {
        router.visit(resendInvitation([shop.slug, invitation.code]), {
            preserveScroll: true,
            onStart: () => setResending(invitation.code),
            onFinish: () => setResending(null),
        });
    };

    return (
        <>
            <Head title={pageTitle} />

            <h1 className="sr-only">{pageTitle}</h1>

            <div className="flex flex-col space-y-10">
                <AttentionList items={attentionItems} />

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
                                options={{ preserveScroll: true }}
                                className="space-y-6"
                            >
                                {({
                                    errors,
                                    processing,
                                    isDirty,
                                    recentlySuccessful,
                                }) => (
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

                                        <div className="grid gap-6 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="prices_include_tax">
                                                    Prices entered
                                                </Label>
                                                <Select
                                                    name="prices_include_tax"
                                                    defaultValue={
                                                        shop.pricesIncludeTax
                                                            ? '1'
                                                            : '0'
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id="prices_include_tax"
                                                        data-test="shop-prices-include-tax"
                                                        className="w-full"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="1">
                                                            Including tax
                                                        </SelectItem>
                                                        <SelectItem value="0">
                                                            Excluding tax
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                <InputError
                                                    message={
                                                        errors.prices_include_tax
                                                    }
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="tax_rate">
                                                    Standard tax rate (%)
                                                </Label>
                                                <Input
                                                    id="tax_rate"
                                                    name="tax_rate"
                                                    type="number"
                                                    inputMode="decimal"
                                                    step="0.01"
                                                    min="0"
                                                    max="100"
                                                    data-test="shop-tax-rate"
                                                    defaultValue={shop.taxRate}
                                                    required
                                                />
                                                <InputError
                                                    message={errors.tax_rate}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="currency">
                                                    Currency
                                                </Label>
                                                <Input
                                                    id="currency"
                                                    name="currency"
                                                    maxLength={3}
                                                    className="uppercase"
                                                    data-test="shop-currency"
                                                    defaultValue={shop.currency}
                                                    required
                                                />
                                                <InputError
                                                    message={errors.currency}
                                                />
                                            </div>
                                        </div>
                                        <p className="text-muted-foreground -mt-3 text-xs">
                                            A WooCommerce sync replaces these
                                            with the store's own tax and
                                            currency settings.
                                        </p>

                                        <SaveButton
                                            processing={processing}
                                            isDirty={isDirty}
                                            recentlySuccessful={
                                                recentlySuccessful
                                            }
                                            data-test="shop-save-button"
                                        />
                                    </>
                                )}
                            </Form>
                        </>
                    ) : (
                        <>
                            <Heading
                                variant="small"
                                title={shop.name}
                                description="Only an owner or admin can change these settings."
                            />
                            <dl
                                className="grid gap-4 text-sm sm:grid-cols-3"
                                data-test="shop-details"
                            >
                                <div>
                                    <dt className="text-muted-foreground">
                                        Shop type
                                    </dt>
                                    <dd className="font-medium">
                                        {shop.typeLabel ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Currency
                                    </dt>
                                    <dd className="font-medium">
                                        {shop.currency}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Your role
                                    </dt>
                                    <dd className="font-medium">
                                        {currentMember?.role_label ?? '—'}
                                    </dd>
                                </div>
                            </dl>
                        </>
                    )}
                </div>

                {integrations.length > 0 ? (
                    <div className="space-y-6 border-t pt-8 first:border-0 first:pt-0">
                        <Heading
                            variant="small"
                            title="Integrations"
                            description="Where this shop's products come from and where they are listed."
                        />

                        <ul className="divide-y overflow-hidden rounded-lg border">
                            {integrations.map((integration) => {
                                const details =
                                    integrationDetails[integration.key];

                                return (
                                    <li key={integration.key}>
                                        <Link
                                            href={integrationHref(
                                                integration.key,
                                            )}
                                            data-test={`shop-${integration.key}-link`}
                                            className="hover:bg-muted/50 flex items-center gap-4 p-4 transition-colors"
                                        >
                                            <div className="bg-muted flex size-10 shrink-0 items-center justify-center rounded-full">
                                                <details.icon className="text-muted-foreground size-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        {details.title}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        data-test={`shop-${integration.key}-status`}
                                                        className={
                                                            statusStyles[
                                                                integration
                                                                    .status
                                                            ]
                                                        }
                                                    >
                                                        {
                                                            integration.statusLabel
                                                        }
                                                    </Badge>
                                                </div>
                                                <p className="text-muted-foreground text-sm">
                                                    {integration.detail ??
                                                        details.description}
                                                </p>
                                            </div>
                                            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
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
                                    : 'Who belongs to this shop'
                            }
                        />

                        {permissions.canCreateInvitation ? (
                            <Button
                                variant="outline"
                                data-test="invite-member-button"
                                onClick={() => setInviteDialogOpen(true)}
                            >
                                <UserPlus /> Invite member
                            </Button>
                        ) : null}
                    </div>

                    <ul className="divide-y overflow-hidden rounded-lg border">
                        {members.map((member) => (
                            <li
                                key={member.id}
                                data-test="member-row"
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="flex min-w-0 items-center gap-4">
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
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2 font-medium">
                                            <span className="truncate">
                                                {member.name}
                                            </span>
                                            {member.id === auth.user.id ? (
                                                <Badge
                                                    variant="outline"
                                                    data-test="member-you"
                                                >
                                                    You
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <div className="text-muted-foreground truncate text-sm">
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
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuRadioGroup
                                                    value={member.role}
                                                    onValueChange={(value) =>
                                                        confirmRoleChange(
                                                            member,
                                                            value,
                                                        )
                                                    }
                                                >
                                                    {availableRoles.map(
                                                        (role) => (
                                                            <DropdownMenuRadioItem
                                                                key={role.value}
                                                                value={
                                                                    role.value
                                                                }
                                                                data-test="member-role-option"
                                                            >
                                                                {role.label}
                                                            </DropdownMenuRadioItem>
                                                        ),
                                                    )}
                                                </DropdownMenuRadioGroup>
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
                                                        aria-label={`Remove ${member.name}`}
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
                            </li>
                        ))}
                    </ul>
                </div>

                {invitations.length > 0 ? (
                    <div
                        id="invitations"
                        className="scroll-mt-6 space-y-6 border-t pt-8 first:border-0 first:pt-0"
                    >
                        <Heading
                            variant="small"
                            title="Pending invitations"
                            description="Invitations that haven't been accepted yet"
                        />

                        <ul className="divide-y overflow-hidden rounded-lg border">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.code}
                                    data-test="invitation-row"
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="flex min-w-0 items-center gap-4">
                                        <div className="bg-muted flex h-10 w-10 shrink-0 items-center justify-center rounded-full">
                                            <Mail className="text-muted-foreground h-5 w-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">
                                                {invitation.email}
                                            </div>
                                            <div className="text-muted-foreground text-sm">
                                                {invitation.role_label} · Sent{' '}
                                                {invitation.sent_at_diff}
                                                {invitation.expires_at_diff ? (
                                                    <span
                                                        data-test={
                                                            invitation.is_expired
                                                                ? 'invitation-expired'
                                                                : undefined
                                                        }
                                                        className={cn(
                                                            invitation.is_expired &&
                                                                'font-medium text-amber-700 dark:text-amber-400',
                                                        )}
                                                    >
                                                        {' · '}
                                                        {invitation.is_expired
                                                            ? `Expired ${invitation.expires_at_diff} ago`
                                                            : `Expires in ${invitation.expires_at_diff}`}
                                                    </span>
                                                ) : null}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {permissions.canCreateInvitation ? (
                                            <Button
                                                variant={
                                                    invitation.is_expired
                                                        ? 'outline'
                                                        : 'ghost'
                                                }
                                                size="sm"
                                                data-test="invitation-resend-button"
                                                disabled={
                                                    resending ===
                                                    invitation.code
                                                }
                                                onClick={() =>
                                                    resend(invitation)
                                                }
                                            >
                                                <Send className="h-4 w-4" />
                                                Resend
                                            </Button>
                                        ) : null}

                                        {permissions.canCancelInvitation ? (
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={`Cancel invitation for ${invitation.email}`}
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
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {permissions.canDeleteShop ? (
                    <div className="border-t pt-8 first:border-0 first:pt-0">
                        <DangerZone
                            title="Delete shop"
                            description="Permanently delete your shop"
                            warning="Everything in the shop goes with it. This cannot be undone."
                        >
                            <Button
                                variant="destructive"
                                data-test="delete-shop-button"
                                onClick={() => setDeleteDialogOpen(true)}
                            >
                                Delete shop
                            </Button>
                        </DangerZone>
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

            <ChangeMemberRoleModal
                shop={shop}
                member={roleChange?.member ?? null}
                role={roleChange?.role ?? null}
                open={roleDialogOpen}
                onOpenChange={setRoleDialogOpen}
            />

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

            {permissions.canDeleteShop ? (
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
