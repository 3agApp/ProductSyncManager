import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { store, update } from '@/routes/products';
import type { Product, ProductStatusOption, Shop } from '@/types';

type Props = {
    shop: Shop;
    product: Product | null;
    statuses: ProductStatusOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function ProductFormModal({
    shop,
    product,
    statuses,
    open,
    onOpenChange,
}: Props) {
    const formRoute = product
        ? update.form([shop.slug, product.id])
        : store.form(shop.slug);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <Form
                    key={`${product?.id ?? 'new'}-${String(open)}`}
                    {...formRoute}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    {product ? 'Edit product' : 'Add product'}
                                </DialogTitle>
                                <DialogDescription>
                                    {product
                                        ? `Update the details for ${product.name}.`
                                        : `Add a new product to ${shop.name}.`}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4">
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

                                <div className="grid gap-4 sm:grid-cols-2">
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
                                        <Label htmlFor="product-status">
                                            Status
                                        </Label>
                                        <Select
                                            name="status"
                                            defaultValue={
                                                product?.status ?? 'active'
                                            }
                                        >
                                            <SelectTrigger
                                                id="product-status"
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

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="product-price">
                                            Price
                                        </Label>
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
                                        <Label htmlFor="product-stock">
                                            Stock
                                        </Label>
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
                                        rows={4}
                                        defaultValue={
                                            product?.description ?? ''
                                        }
                                        placeholder="What makes this product special?"
                                    />
                                    <InputError message={errors.description} />
                                </div>
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="product-submit"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {product ? 'Save changes' : 'Add product'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
