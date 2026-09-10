import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus, Store } from 'lucide-react';
import CreateShopModal from '@/components/create-shop-modal';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useIsMobile } from '@/hooks/use-mobile';
import { switchMethod } from '@/routes/shops';
import type { Shop } from '@/types';

export function ShopSwitcher() {
    const page = usePage();
    const isMobile = useIsMobile();
    const currentShop = page.props.currentShop;
    const shops = page.props.shops ?? [];

    const switchShop = (shop: Shop) => {
        const previousShopSlug = currentShop?.slug;

        router.visit(switchMethod(shop.slug), {
            onFinish: () => {
                if (!previousShopSlug || typeof window === 'undefined') {
                    router.reload();

                    return;
                }

                const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
                const segment = `/${previousShopSlug}`;

                if (currentUrl.includes(segment)) {
                    router.visit(currentUrl.replace(segment, `/${shop.slug}`), {
                        replace: true,
                    });

                    return;
                }

                router.reload();
            },
        });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    data-test="shop-switcher-trigger"
                    className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground w-full justify-start px-2 has-[>svg]:px-2"
                >
                    <Store className="hidden size-4 shrink-0 group-data-[collapsible=icon]:block" />
                    <div className="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
                        <span className="truncate font-semibold">
                            {currentShop?.name ?? 'Select shop'}
                        </span>
                    </div>
                    <ChevronsUpDown className="ml-auto group-data-[collapsible=icon]:hidden" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                side={isMobile ? 'bottom' : 'right'}
                align="start"
                sideOffset={4}
            >
                <DropdownMenuLabel className="text-muted-foreground text-xs">
                    Shops
                </DropdownMenuLabel>
                {shops.map((shop) => (
                    <DropdownMenuItem
                        key={shop.id}
                        data-test="shop-switcher-item"
                        className="cursor-pointer gap-2 p-2"
                        onSelect={() => switchShop(shop)}
                    >
                        {shop.name}
                        {currentShop?.id === shop.id && (
                            <Check className="ml-auto h-4 w-4" />
                        )}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <CreateShopModal>
                    <DropdownMenuItem
                        data-test="shop-switcher-new-shop"
                        className="cursor-pointer gap-2 p-2"
                        onSelect={(event) => event.preventDefault()}
                    >
                        <Plus className="h-4 w-4" />
                        <span className="text-muted-foreground">New shop</span>
                    </DropdownMenuItem>
                </CreateShopModal>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
