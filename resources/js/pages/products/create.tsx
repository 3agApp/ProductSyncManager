import { Head } from '@inertiajs/react';
import ProductForm from '@/components/product-form';
import { dashboard } from '@/routes';
import type { ProductStatusOption, Shop } from '@/types';

type Props = {
    shop: Shop;
    statuses: ProductStatusOption[];
};

export default function ProductCreate({ shop, statuses }: Props) {
    return (
        <>
            <Head title="Add product" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="space-y-0.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Add product
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Add a new product to {shop.name}.
                    </p>
                </div>

                <div className="max-w-3xl">
                    <ProductForm
                        shop={shop}
                        product={null}
                        statuses={statuses}
                    />
                </div>
            </div>
        </>
    );
}

ProductCreate.layout = (props: { shop: { slug: string } }) => ({
    breadcrumbs: [
        { title: 'Products', href: dashboard(props.shop.slug) },
        { title: 'Add product', href: '' },
    ],
});
