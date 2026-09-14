import { Link, usePage } from '@inertiajs/react';
import {
    FolderTree,
    LayoutGrid,
    Mail,
    Package,
    Settings2,
    Tags,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { ShopSwitcher } from '@/components/shop-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as brandsIndex } from '@/routes/brands';
import { index as categoriesIndex } from '@/routes/categories';
import { index as invitationsIndex } from '@/routes/invitations';
import { index as productsIndex } from '@/routes/products';
import { edit as editShop } from '@/routes/shops';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { currentShop, pendingInvitationsCount } = usePage().props;
    const dashboardUrl = currentShop ? dashboard(currentShop.slug) : '/';

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardUrl,
            icon: LayoutGrid,
        },
    ];

    if (currentShop) {
        mainNavItems.push(
            {
                title: 'Products',
                href: productsIndex(currentShop.slug),
                icon: Package,
            },
            {
                title: 'Categories',
                href: categoriesIndex(currentShop.slug),
                icon: FolderTree,
            },
            {
                title: 'Brands',
                href: brandsIndex(currentShop.slug),
                icon: Tags,
            },
            {
                title: 'Shop settings',
                href: editShop(currentShop.slug),
                icon: Settings2,
            },
        );
    }

    if (pendingInvitationsCount > 0) {
        mainNavItems.push({
            title: 'Invitations',
            href: invitationsIndex(),
            icon: Mail,
            badge: pendingInvitationsCount,
        });
    }

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboardUrl} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <ShopSwitcher />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
