import type { SyncRunStatus } from './sync';

export type NaldaDefaultBehavior = 'include_all' | 'exclude_all';

export type NaldaListing = 'default' | 'include' | 'exclude';

export type NaldaPriceMode = 'as_is' | 'add_tax';

export type NaldaPriceModeOption = {
    value: NaldaPriceMode;
    label: string;
};

export type ShopTax = {
    pricesIncludeTax: boolean;
    taxRate: string;
    naldaTaxRate: string;
};

export type NaldaBehaviorOption = {
    value: NaldaDefaultBehavior;
    label: string;
};

export type NaldaListingOption = {
    value: NaldaListing;
    label: string;
};

export type NaldaConnection = {
    isConfigured: boolean;
    sftpHost: string;
    sftpPort: number;
    sftpUsername: string;
    hasPassword: boolean;
    isUsable: boolean;
    verifiedAt: string | null;
    fileName: string;
    defaultBehavior: NaldaDefaultBehavior;
    country: string;
    currency: string;
    condition: string;
    priceMode: NaldaPriceMode;
    deliveryTimeDays: number;
    returnDays: number;
    uploadAfterSync: boolean;
    lastExportedAt: string | null;
    /** Whether a seller API key is saved; the key itself never reaches the browser. */
    hasApiKey: boolean;
    /** Create Nalda orders in WooCommerce and send their statuses back. */
    importOrders: boolean;
    ordersCheckedAt: string | null;
    statusesExportedAt: string | null;
};

export type NaldaExport = {
    id: number;
    status: SyncRunStatus;
    statusLabel: string;
    isActive: boolean;
    triggerLabel: string;
    listedProducts: number;
    removedProducts: number;
    skippedProducts: number;
    fileName: string | null;
    message: string | null;
    durationInSeconds: number | null;
    createdAtDiff: string | null;
};
