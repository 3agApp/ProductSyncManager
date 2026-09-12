export type ProductStatus = 'active' | 'draft' | 'archived';

export type Product = {
    id: number;
    remoteId: number | null;
    name: string;
    sku: string;
    gtin: string | null;
    description: string | null;
    shortDescription: string | null;
    brand: string | null;
    categories: string[];
    imageUrl: string | null;
    galleryImageUrls: string[];
    permalink: string | null;
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
    brands: number;
    categories: number;
};

export type ProductFilters = {
    search: string;
    status: ProductStatus | null;
    /** A category id, "none" for products filed nowhere, or null. */
    category: string | null;
    /** A brand id, "none" for products with no brand, or null. */
    brand: string | null;
    sort: ProductSort;
    direction: 'asc' | 'desc';
    perPage: number;
};

export type ProductSort = 'name' | 'price' | 'stock' | 'updated';

export type FilterOption = {
    value: string;
    label: string;
};

export type ProductStatusOption = {
    value: ProductStatus;
    label: string;
};
