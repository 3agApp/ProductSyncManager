import { Head } from '@inertiajs/react';
import ProductForm from '@/components/product-form';
import { dashboard } from '@/routes';
import type { Product, ProductStatusOption, Shop } from '@/types';

type Props = {
    shop: Shop;
    product: Product;
    brands: string[];
    statuses: ProductStatusOption[];
};

export default function ProductEdit({
    shop,
    product,
    brands,
    statuses,
}: Props) {
    return (
        <>
            <Head title={`Edit ${product.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="space-y-0.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Edit product
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Update the details for {product.name}.
                    </p>
                </div>

                <div className="max-w-3xl">
                    <ProductForm
                        shop={shop}
                        product={product}
                        brands={brands}
                        statuses={statuses}
                    />
                </div>
            </div>
        </>
    );
}

ProductEdit.layout = (props: {
    shop: { slug: string };
    product: { name: string };
}) => ({
    breadcrumbs: [
        { title: 'Products', href: dashboard(props.shop.slug) },
        { title: props.product.name, href: '' },
    ],
});
