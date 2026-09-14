import { Head } from '@inertiajs/react';
import ProductForm from '@/components/product-form';
import { index as productsIndex } from '@/routes/products';
import type {
    NaldaListingOption,
    TemuListingOption,
    Product,
    ProductStatusOption,
    Shop,
} from '@/types';

type Props = {
    shop: Shop;
    product: Product;
    brands: string[];
    categories: string[];
    statuses: ProductStatusOption[];
    naldaListings: NaldaListingOption[];
    temuListings: TemuListingOption[];
};

export default function ProductEdit({
    shop,
    product,
    brands,
    categories,
    statuses,
    naldaListings,
    temuListings,
}: Props) {
    return (
        <>
            <Head title={`Edit ${product.name}`} />

            <div className="workspace-page">
                <div className="page-heading">
                    <h1 className="page-title">Edit product</h1>
                    <p className="text-muted-foreground text-sm">
                        Update the details for {product.name}.
                    </p>
                </div>

                <div className="w-full max-w-4xl">
                    <ProductForm
                        shop={shop}
                        product={product}
                        brands={brands}
                        categories={categories}
                        statuses={statuses}
                        naldaListings={naldaListings}
                        temuListings={temuListings}
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
        { title: 'Products', href: productsIndex(props.shop.slug) },
        { title: props.product.name, href: '' },
    ],
});
