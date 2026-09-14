export type ShopRole = 'owner' | 'admin' | 'member';

export type ShopType = 'wordpress';

export type Shop = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    type?: ShopType;
    typeLabel?: string;
    role?: ShopRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type ShopTypeOption = {
    value: ShopType;
    label: string;
};

export type ShopMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: ShopRole;
    role_label: string;
};

export type ShopInvitation = {
    code: string;
    email: string;
    role: ShopRole;
    role_label: string;
    created_at: string;
};

export type ShopInvitationContext = {
    code: string;
    shopName: string;
};

export type PendingInvitation = {
    code: string;
    inviterName: string;
    roleLabel: string;
    expiresAt: string | null;
    shop: {
        name: string;
        slug: string;
    };
};

export type ShopPermissions = {
    canUpdateShop: boolean;
    canDeleteShop: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
    canCreateProduct: boolean;
    canUpdateProduct: boolean;
    canDeleteProduct: boolean;
    canCreateBrand: boolean;
    canUpdateBrand: boolean;
    canDeleteBrand: boolean;
    canCreateCategory: boolean;
    canUpdateCategory: boolean;
    canDeleteCategory: boolean;
    canManageSync: boolean;
    canRunSync: boolean;
};

export type RoleOption = {
    value: ShopRole;
    label: string;
};
