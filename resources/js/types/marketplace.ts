export type MarketplaceOrderStatus =
    | 'pending'
    | 'creating'
    | 'imported'
    | 'failed'
    | 'cancelled';

/** A Nalda or Temu order and the WooCommerce order it was imported as. */
export type MarketplaceOrder = {
    id: number;
    /** The marketplace's own order number. */
    externalOrderId: string;
    status: MarketplaceOrderStatus;
    statusLabel: string;
    /** Where the order stands on the marketplace. */
    marketplaceStatus: string;
    /** The delivery state reported back, as the WooCommerce plugin keeps it. */
    deliveryStatus: string | null;
    needsAttention: boolean;
    message: string | null;
    orderedAt: string | null;
    shipByAt: string | null;
    /** An order Temu still waits on that is past, or close to, its ship-by time. */
    shipByWarning: 'overdue' | 'soon' | null;
    shipmentConfirmedAt: string | null;
    /** Why Temu refused the shipment confirmation, until it is sent again. */
    shipmentMessage: string | null;
    wooCommerceOrderId: number | null;
    wooCommerceUrl: string | null;
};
