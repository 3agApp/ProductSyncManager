import type { Auth } from '@/types/auth';
import type { Shop, ShopTypeOption } from '@/types/shops';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            impersonating: boolean;
            sidebarOpen: boolean;
            currentShop: Shop | null;
            shops: Shop[];
            shopTypes: ShopTypeOption[];
            pendingInvitationsCount: number;
            accountsUrl: string;
            [key: string]: unknown;
        };
    }
}
