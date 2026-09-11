import { ExternalLink, Package } from 'lucide-react';
import { ProductStatusBadge } from '@/components/product-status-badge';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatCurrency } from '@/lib/utils';
import type { Product } from '@/types';

type Props = {
    product: Product | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type FieldProps = {
    label: string;
    children: React.ReactNode;
};

function Field({ label, children }: FieldProps) {
    return (
        <div className="grid gap-1">
            <p className="text-muted-foreground text-xs">{label}</p>
            <div className="text-sm">{children}</div>
        </div>
    );
}

export default function ProductDetailModal({
    product,
    open,
    onOpenChange,
}: Props) {
    if (!product) {
        return null;
    }

    const images = [
        ...(product.imageUrl ? [product.imageUrl] : []),
        ...product.galleryImageUrls,
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle data-test="product-detail-name">
                        {product.name}
                    </DialogTitle>
                    <DialogDescription>
                        <span className="font-mono">{product.sku}</span>
                        {product.gtin ? <> · GTIN {product.gtin}</> : null}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-6">
                    {images.length > 0 ? (
                        <div className="flex gap-2 overflow-x-auto pb-1">
                            {images.map((url) => (
                                <img
                                    key={url}
                                    src={url}
                                    alt=""
                                    loading="lazy"
                                    data-test="product-detail-image"
                                    className="border-sidebar-border/70 dark:border-sidebar-border size-24 shrink-0 rounded-lg border object-cover"
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground flex size-24 items-center justify-center rounded-lg border">
                            <Package className="size-6" />
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Price">
                            <span className="tabular-nums">
                                {formatCurrency(product.price)}
                            </span>
                        </Field>
                        <Field label="Stock">
                            <span className="tabular-nums">
                                {product.stock.toLocaleString()}
                            </span>
                        </Field>
                        <Field label="Status">
                            <ProductStatusBadge
                                status={product.status}
                                label={product.statusLabel}
                            />
                        </Field>
                    </div>

                    {product.brand ? (
                        <Field label="Brand">
                            <Badge variant="secondary">{product.brand}</Badge>
                        </Field>
                    ) : null}

                    {product.categories.length > 0 ? (
                        <Field label="Categories">
                            <div
                                className="flex flex-wrap gap-1"
                                data-test="product-detail-categories"
                            >
                                {product.categories.map((category) => (
                                    <Badge key={category} variant="outline">
                                        {category}
                                    </Badge>
                                ))}
                            </div>
                        </Field>
                    ) : null}

                    {product.shortDescription ? (
                        <Field label="Short description">
                            <p className="whitespace-pre-line">
                                {product.shortDescription}
                            </p>
                        </Field>
                    ) : null}

                    {product.description ? (
                        <Field label="Description">
                            <p className="text-muted-foreground whitespace-pre-line">
                                {product.description}
                            </p>
                        </Field>
                    ) : null}

                    {product.permalink ? (
                        <a
                            href={product.permalink}
                            target="_blank"
                            rel="noreferrer noopener"
                            data-test="product-detail-permalink"
                            className="text-primary inline-flex items-center gap-1 text-sm underline underline-offset-4"
                        >
                            View on the store
                            <ExternalLink className="size-3.5" />
                        </a>
                    ) : null}
                </div>
            </DialogContent>
        </Dialog>
    );
}
