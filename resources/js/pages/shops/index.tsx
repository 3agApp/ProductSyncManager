import { Head, Link } from '@inertiajs/react';
import { Eye, LogOut, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import CreateShopModal from '@/components/create-shop-modal';
import Heading from '@/components/heading';
import LeaveShopModal from '@/components/leave-shop-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { edit, index } from '@/routes/shops';
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

                <div className="space-y-3">
                    {shops.map((shop) => {
                        const canLeaveShop =
                            !shop.isPersonal && shop.role !== 'owner';

                        return (
                            <div
                                key={shop.id}
                                data-test="shop-row"
                                className="bg-muted/20 flex flex-wrap items-center justify-between gap-4 rounded-xl border p-4"
                            >
                                <div className="min-w-0 flex-1">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium break-words">
                                                {shop.name}
                                            </span>
                                            {shop.isPersonal ? (
                                                <Badge variant="secondary">
                                                    Personal
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <span className="text-muted-foreground text-sm">
                                            {shop.roleLabel}
                                        </span>
                                    </div>
                                </div>

                                <TooltipProvider>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {canLeaveShop ? (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="shop-leave-button"
                                                        onClick={() =>
                                                            openLeaveShopDialog(
                                                                shop,
                                                            )
                                                        }
                                                    >
                                                        <LogOut className="h-4 w-4" />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>Leave shop</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        ) : null}

                                        {shop.role === 'member' ? (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="shop-view-button"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={edit(
                                                                shop.slug,
                                                            )}
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>View shop</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        ) : (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="shop-edit-button"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={edit(
                                                                shop.slug,
                                                            )}
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>Edit shop</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        )}
                                    </div>
                                </TooltipProvider>
                            </div>
                        );
                    })}

                    {shops.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center">
                            You don't belong to any shops yet.
                        </p>
                    ) : null}
                </div>
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
