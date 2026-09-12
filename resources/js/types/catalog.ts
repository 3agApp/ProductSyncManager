export type Brand = {
    id: number;
    name: string;
    slug: string;
    productCount: number;
};

export type Category = {
    id: number;
    parentId: number | null;
    name: string;
    slug: string;
    /** The full written path, such as "Bags > Totes". */
    path: string;
    /** How many levels down the tree the category sits; 0 is the top. */
    depth: number;
    remoteId: number | null;
    productCount: number;
    childCount: number;
};
