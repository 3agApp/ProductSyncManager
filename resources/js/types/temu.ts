import type { SyncRunStatus } from './sync';

export type TemuDefaultBehavior = 'include_all' | 'exclude_all';

export type TemuListing = 'default' | 'include' | 'exclude';

export type TemuProductStatus =
    | 'pending'
    | 'processing'
    | 'draft'
    | 'active'
    | 'inactive'
    | 'rejected'
    | 'deleted'
    | 'failed';

export type TemuBehaviorOption = {
    value: TemuDefaultBehavior;
    label: string;
};

export type TemuListingOption = {
    value: TemuListing;
    label: string;
};

export type TemuConnection = {
    isConfigured: boolean;
    gateway: string;
    appKey: string;
    hasSecret: boolean;
    hasToken: boolean;
    isUsable: boolean;
    verifiedAt: string | null;
    mallId: number | null;
    tokenExpiresAt: string | null;
    tokenHasExpired: boolean;
    defaultBehavior: TemuDefaultBehavior;
    currency: string;
    /** Percent off the shop price on Temu, unless a brand sets its own. */
    discountPercent: number;
    syncAfterSync: boolean;
    /** Create Temu orders in WooCommerce, which fulfils them. */
    importOrders: boolean;
    /** What Temu pays for shipping one order, tax included. */
    orderShipping: number;
    ordersCheckedAt: string | null;
    lastSyncedAt: string | null;
};

export type TemuOrderStatus =
    | 'pending'
    | 'creating'
    | 'imported'
    | 'failed'
    | 'cancelled';

export type TemuOrder = {
    id: number;
    parentOrderSn: string;
    status: TemuOrderStatus;
    statusLabel: string;
    /** Where the order stands on Temu. */
    temuStatus: string;
    needsAttention: boolean;
    message: string | null;
    orderedAt: string | null;
    shipByAt: string | null;
    wooCommerceOrderId: number | null;
    wooCommerceUrl: string | null;
};

export type TemuSyncRun = {
    id: number;
    status: SyncRunStatus;
    statusLabel: string;
    isActive: boolean;
    triggerLabel: string;
    createdProducts: number;
    updatedProducts: number;
    removedProducts: number;
    failedProducts: number;
    skippedProducts: number;
    message: string | null;
    durationInSeconds: number | null;
    createdAtDiff: string | null;
};

export type TemuStatusCount = {
    status: TemuProductStatus;
    label: string;
    count: number;
};

export type TemuProblem = {
    id: number;
    productId: number | null;
    productName: string;
    sku: string;
    status: TemuProductStatus;
    statusLabel: string;
    temuStatus: string | null;
    goodsId: number | null;
    message: string | null;
    checkedAtDiff: string | null;
    canRetry: boolean;
    reason: TemuProblemReason;
};

/** Why a product needs attention, worked out from Temu's answer. */
export type TemuProblemReason = {
    /** Products with the same key are shown as one group. */
    key: string;
    title: string;
    hint: string | null;
    /** What fixes it: a better Temu title, a retry, or Temu Seller Center. */
    fix: 'title' | 'retry' | 'seller_center' | null;
};
