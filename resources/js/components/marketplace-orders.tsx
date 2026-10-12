import { router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { retry as retryOrder } from '@/routes/shops/marketplace-orders';
import type { MarketplaceOrder, MarketplaceOrderStatus } from '@/types';

const ORDER_VARIANTS: Record<MarketplaceOrderStatus, string> = {
    pending: 'text-muted-foreground',
    creating: 'text-amber-600 dark:text-amber-400',
    imported: 'text-emerald-600 dark:text-emerald-400',
    failed: 'text-red-600 dark:text-red-400',
    cancelled: 'text-muted-foreground',
};

export function formatDateTime(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'short',
              timeStyle: 'short',
          })
        : '—';
}

function readable(state: string | null): string {
    if (!state) {
        return '—';
    }

    const words = state.toLowerCase().replaceAll('_', ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

type Props = {
    /** Display name of the marketplace, e.g. "Nalda". */
    marketplace: string;
    shopSlug: string;
    orders: MarketplaceOrder[];
    canRetry: boolean;
    /** Whether to show the ship-by deadline, which only Temu sets. */
    showShipBy?: boolean;
};

/**
 * The recent orders a marketplace sent, as imported into WooCommerce.
 */
export default function MarketplaceOrders({
    marketplace,
    shopSlug,
    orders,
    canRetry,
    showShipBy = false,
}: Props) {
    const retryImport = (order: MarketplaceOrder) => {
        if (
            order.status === 'creating' &&
            !window.confirm(
                `Check WooCommerce first: the import of ${order.externalOrderId} was cut off and the order may already be there. Import it again?`,
            )
        ) {
            return;
        }

        router.visit(
            retryOrder({ shop: shopSlug, marketplaceOrder: order.id }),
            { preserveScroll: true },
        );
    };

    if (orders.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                No {marketplace} orders have been imported yet.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{marketplace} order</TableHead>
                        <TableHead>Import</TableHead>
                        <TableHead>On {marketplace}</TableHead>
                        <TableHead>Delivery</TableHead>
                        <TableHead>Ordered</TableHead>
                        {showShipBy ? <TableHead>Ship by</TableHead> : null}
                        <TableHead>WooCommerce</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {orders.map((order) => (
                        <TableRow
                            key={order.id}
                            data-test="marketplace-order-row"
                        >
                            <TableCell className="font-mono text-xs">
                                {order.externalOrderId}
                                {order.message ? (
                                    <p className="text-muted-foreground mt-1 max-w-sm font-sans text-xs whitespace-normal">
                                        {order.message}
                                    </p>
                                ) : null}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    variant="outline"
                                    className={ORDER_VARIANTS[order.status]}
                                >
                                    {order.statusLabel}
                                </Badge>
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {order.marketplaceStatus || '—'}
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {readable(order.deliveryStatus)}
                            </TableCell>
                            <TableCell className="text-muted-foreground">
                                {formatDateTime(order.orderedAt)}
                            </TableCell>
                            {showShipBy ? (
                                <TableCell className="text-muted-foreground">
                                    {formatDateTime(order.shipByAt)}
                                </TableCell>
                            ) : null}
                            <TableCell>
                                {order.wooCommerceUrl ? (
                                    <a
                                        href={order.wooCommerceUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline underline-offset-4"
                                    >
                                        #{order.wooCommerceOrderId}
                                    </a>
                                ) : order.needsAttention && canRetry ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        data-test="marketplace-order-retry"
                                        onClick={() => retryImport(order)}
                                    >
                                        Import again
                                    </Button>
                                ) : (
                                    '—'
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
