export type ProductStatus = 'active' | 'draft' | 'archived';

export type Product = {
    id: number;
    name: string;
    sku: string;
    description: string | null;
    price: string;
    stock: number;
    status: ProductStatus;
    statusLabel: string;
    updatedAt: string | null;
    updatedAtDiff: string | null;
};

export type ProductStats = {
    total: number;
    active: number;
    draft: number;
    lowStock: number;
    outOfStock: number;
    inventoryValue: number;
};

export type ProductFilters = {
    search: string;
    status: ProductStatus | null;
};

export type ProductStatusOption = {
    value: ProductStatus;
    label: string;
};
