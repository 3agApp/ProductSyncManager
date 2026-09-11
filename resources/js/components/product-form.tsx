import { Form, Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { store, update } from '@/routes/products';
import type { Product, ProductStatusOption, Shop } from '@/types';

type Props = {
    shop: Shop;
    product: Product | null;
    statuses: ProductStatusOption[];
};

type SectionProps = PropsWithChildren<{
    title: string;
    description: string;
}>;

function Section({ title, description, children }: SectionProps) {
    return (
        <section className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <div className="space-y-0.5">
                <h2 className="text-base font-medium">{title}</h2>
                <p className="text-muted-foreground text-sm">{description}</p>
            </div>

            <div className="mt-4 grid gap-6">{children}</div>
        </section>
    );
}

export default function ProductForm({ shop, product, statuses }: Props) {
    const formRoute = product
        ? update.form([shop.slug, product.id])
        : store.form(shop.slug);

    const isSynced = Boolean(product?.remoteId);

    return (
        <Form {...formRoute} className="space-y-4">
            {({ errors, processing }) => (
                <>
                    {isSynced ? (
                        <p
                            className="text-muted-foreground text-sm"
                            data-test="product-synced-notice"
                        >
                            This product is synced from WooCommerce. Changes
                            here are replaced the next time a sync runs.
                        </p>
                    ) : null}

                    <Section
                        title="Details"
                        description="How the product is identified in your catalog."
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="product-name">Name</Label>
                            <Input
                                id="product-name"
                                name="name"
                                data-test="product-name"
                                defaultValue={product?.name}
                                placeholder="Classic Cotton Tote Bag"
                                required
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-6 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="product-sku">SKU</Label>
                                <Input
                                    id="product-sku"
                                    name="sku"
                                    data-test="product-sku"
                                    defaultValue={product?.sku}
                                    placeholder="TOT-10421"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={errors.sku} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="product-gtin">
                                    GTIN{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="product-gtin"
                                    name="gtin"
                                    data-test="product-gtin"
                                    defaultValue={product?.gtin ?? ''}
                                    placeholder="5012345678900"
                                    autoComplete="off"
                                />
                                <InputError message={errors.gtin} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="product-status">Status</Label>
                                <Select
                                    name="status"
                                    defaultValue={product?.status ?? 'active'}
                                >
                                    <SelectTrigger
                                        id="product-status"
                                        data-test="product-status"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((status) => (
                                            <SelectItem
                                                key={status.value}
                                                value={status.value}
                                            >
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.status} />
                            </div>
                        </div>
                    </Section>

                    <Section
                        title="Pricing and inventory"
                        description="What the product costs and how many you hold."
                    >
                        <div className="grid gap-6 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="product-price">Price</Label>
                                <div className="relative">
                                    <span className="text-muted-foreground pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm">
                                        $
                                    </span>
                                    <Input
                                        id="product-price"
                                        name="price"
                                        type="number"
                                        inputMode="decimal"
                                        step="0.01"
                                        min="0"
                                        data-test="product-price"
                                        defaultValue={product?.price}
                                        placeholder="0.00"
                                        className="pl-7"
                                        required
                                    />
                                </div>
                                <InputError message={errors.price} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="product-stock">Stock</Label>
                                <Input
                                    id="product-stock"
                                    name="stock"
                                    type="number"
                                    inputMode="numeric"
                                    step="1"
                                    min="0"
                                    data-test="product-stock"
                                    defaultValue={product?.stock ?? 0}
                                    required
                                />
                                <InputError message={errors.stock} />
                            </div>
                        </div>
                    </Section>

                    <Section
                        title="Descriptions"
                        description="The short blurb shown in listings, and the full copy."
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="product-short-description">
                                Short description{' '}
                                <span className="text-muted-foreground font-normal">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="product-short-description"
                                name="short_description"
                                data-test="product-short-description"
                                rows={3}
                                defaultValue={product?.shortDescription ?? ''}
                                placeholder="A sturdy everyday tote."
                            />
                            <InputError message={errors.short_description} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="product-description">
                                Description{' '}
                                <span className="text-muted-foreground font-normal">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="product-description"
                                name="description"
                                data-test="product-description"
                                rows={8}
                                defaultValue={product?.description ?? ''}
                                placeholder="What makes this product special?"
                            />
                            <InputError message={errors.description} />
                        </div>
                    </Section>

                    <Section
                        title="Organization"
                        description="Brands and categories, entered as comma separated lists."
                    >
                        <div className="grid gap-6 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="product-brands">
                                    Brands{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="product-brands"
                                    name="brands"
                                    data-test="product-brands"
                                    defaultValue={
                                        product?.brands.join(', ') ?? ''
                                    }
                                    placeholder="Acme, Globex"
                                />
                                <InputError message={errors.brands} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="product-categories">
                                    Categories{' '}
                                    <span className="text-muted-foreground font-normal">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="product-categories"
                                    name="categories"
                                    data-test="product-categories"
                                    defaultValue={
                                        product?.categories.join(', ') ?? ''
                                    }
                                    placeholder="Bags, Accessories"
                                />
                                <InputError message={errors.categories} />
                            </div>
                        </div>
                    </Section>

                    <Section
                        title="Media and links"
                        description="Images are stored as links; the files stay where they are hosted."
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="product-image-url">
                                Primary image URL{' '}
                                <span className="text-muted-foreground font-normal">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="product-image-url"
                                name="image_url"
                                type="url"
                                data-test="product-image-url"
                                defaultValue={product?.imageUrl ?? ''}
                                placeholder="https://example.com/tote.jpg"
                            />
                            <InputError message={errors.image_url} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="product-gallery">
                                Gallery image URLs{' '}
                                <span className="text-muted-foreground font-normal">
                                    (one per line)
                                </span>
                            </Label>
                            <Textarea
                                id="product-gallery"
                                name="gallery_image_urls"
                                data-test="product-gallery"
                                rows={3}
                                defaultValue={
                                    product?.galleryImageUrls.join('\n') ?? ''
                                }
                                placeholder={'https://example.com/tote-2.jpg'}
                            />
                            <InputError message={errors.gallery_image_urls} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="product-permalink">
                                Store link{' '}
                                <span className="text-muted-foreground font-normal">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="product-permalink"
                                name="permalink"
                                type="url"
                                data-test="product-permalink"
                                defaultValue={product?.permalink ?? ''}
                                placeholder="https://example.com/product/tote"
                            />
                            <InputError message={errors.permalink} />
                        </div>
                    </Section>

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            data-test="product-submit"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {product ? 'Save changes' : 'Add product'}
                        </Button>

                        <Button type="button" variant="secondary" asChild>
                            <Link
                                href={dashboard(shop.slug)}
                                data-test="product-cancel"
                            >
                                Cancel
                            </Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
