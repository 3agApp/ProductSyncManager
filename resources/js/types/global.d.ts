import type { Auth } from '@/types/auth';
import type { Shop, ShopTypeOption } from '@/types/shops';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentShop: Shop | null;
            shops: Shop[];
            shopTypes: ShopTypeOption[];
            [key: string]: unknown;
        };
    }
}
