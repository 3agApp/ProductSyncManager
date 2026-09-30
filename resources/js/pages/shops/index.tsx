import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRightLeft,
    ChevronRight,
    LogOut,
    MoreHorizontal,
    Plus,
    Store,
} from 'lucide-react';
import { useState } from 'react';
import CreateShopModal from '@/components/create-shop-modal';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import LeaveShopModal from '@/components/leave-shop-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { edit, index, switchMethod } from '@/routes/shops';
import type { Shop } from '@/types';

type Props = {
    shops: Shop[];
};

export default function ShopsIndex({ shops }: Props) {
    const [leaveShopDialogOpen, setLeaveShopDialogOpen] = useState(false);
    const [shopLeaving, setShopLeaving] = useState<Shop | null>(null);

    const openLeaveShopDialog = (shop: Shop) => {
        setShopLeaving(shop);
        setLeaveShopDialogOpen(true);
    };

    const switchTo = (shop: Shop) => {
        router.visit(switchMethod(shop.slug), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Shops" />

            <h1 className="sr-only">Shops</h1>

            <div className="flex flex-col space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Shops"
                        description="Manage your shops and shop memberships"
                    />

                    <CreateShopModal>
                        <Button data-test="shops-new-shop-button">
                            <Plus /> New shop
                        </Button>
                    </CreateShopModal>
                </div>

                {shops.length === 0 ? (
                    <div className="rounded-lg border">
                        <EmptyState
                            icon={Store}
                            title="You don't belong to any shops yet"
                            description="Create one, or ask an owner to invite you to theirs."
                        />
                    </div>
                ) : (
                    <ul className="divide-y overflow-hidden rounded-lg border">
                        {shops.map((shop) => {
                            const canLeaveShop = shop.role !== 'owner';
                            const hasMenu = canLeaveShop || !shop.isCurrent;

                            return (
                                <li
                                    key={shop.id}
                                    data-test="shop-row"
                                    className="hover:bg-muted/50 flex items-center gap-2 pr-2 transition-colors"
                                >
                                    <Link
                                        href={edit(shop.slug)}
                                        data-test={
                                            shop.role === 'member'
                                                ? 'shop-view-button'
                                                : 'shop-edit-button'
                                        }
                                        className="flex min-w-0 flex-1 items-center gap-4 p-4"
                                    >
                                        <div className="bg-muted flex size-10 shrink-0 items-center justify-center rounded-full">
                                            <Store className="text-muted-foreground size-5" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium break-words">
                                                    {shop.name}
                                                </span>
                                                {shop.isCurrent ? (
                                                    <Badge
                                                        variant="outline"
                                                        data-test="shop-current"
                                                    >
                                                        Current
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <span className="text-muted-foreground text-sm">
                                                {shop.roleLabel}
                                            </span>
                                        </div>
                                        <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                    </Link>

                                    {hasMenu ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`More actions for ${shop.name}`}
                                                    data-test="shop-actions"
                                                >
                                                    <MoreHorizontal className="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {!shop.isCurrent ? (
                                                    <DropdownMenuItem
                                                        data-test="shop-switch-button"
                                                        onSelect={() =>
                                                            switchTo(shop)
                                                        }
                                                    >
                                                        <ArrowRightLeft />
                                                        Switch to this shop
                                                    </DropdownMenuItem>
                                                ) : null}
                                                {canLeaveShop ? (
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        data-test="shop-leave-button"
                                                        onSelect={() =>
                                                            openLeaveShopDialog(
                                                                shop,
                                                            )
                                                        }
                                                    >
                                                        <LogOut />
                                                        Leave shop
                                                    </DropdownMenuItem>
                                                ) : null}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <LeaveShopModal
                shop={shopLeaving}
                open={leaveShopDialogOpen}
                onOpenChange={setLeaveShopDialogOpen}
            />
        </>
    );
}

ShopsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Shops',
            href: index(),
        },
    ],
};
