import { Head } from '@inertiajs/react';
import ProductForm from '@/components/product-form';
import { index as productsIndex } from '@/routes/products';
import type { ProductStatusOption, Shop } from '@/types';

type Props = {
    shop: Shop;
    brands: string[];
    categories: string[];
    statuses: ProductStatusOption[];
};

export default function ProductCreate({
    shop,
    brands,
    categories,
    statuses,
}: Props) {
    return (
        <>
            <Head title="Add product" />

            <div className="workspace-page">
                <div className="page-heading">
                    <h1 className="page-title">Add product</h1>
                    <p className="text-muted-foreground text-sm">
                        Add a new product to {shop.name}.
                    </p>
                </div>

                <div className="w-full max-w-4xl">
                    <ProductForm
                        shop={shop}
                        product={null}
                        brands={brands}
                        categories={categories}
                        statuses={statuses}
                    />
                </div>
            </div>
        </>
    );
}

ProductCreate.layout = (props: { shop: { slug: string } }) => ({
    breadcrumbs: [
        { title: 'Products', href: productsIndex(props.shop.slug) },
        { title: 'Add product', href: '' },
    ],
});
