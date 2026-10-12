<?php

namespace App\Actions\Orders;

use App\Enums\MarketplaceOrderStatus;
use App\Models\MarketplaceOrder;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Http\Client\ConnectionException;

/**
 * Creates one marketplace order in WooCommerce, at most once.
 *
 * The row is claimed as creating before the request, so two runs can never
 * both send it. A request cut off before WooCommerce answered may still have
 * created the order, so the row stays creating for someone to check rather
 * than being sent again.
 */
class CreateWooCommerceOrder
{
    /**
     * Send the order and record the outcome on the row.
     *
     * @param  array<string, mixed>  $payload  The create-order request.
     *
     * @throws WooCommerceRateLimitException when the store asks to wait; the row is pending again.
     */
    public function handle(MarketplaceOrder $row, WooCommerceClient $client, array $payload, string $note): void
    {
        $claimed = MarketplaceOrder::whereKey($row->id)
            ->where('status', MarketplaceOrderStatus::Pending->value)
            ->update(['status' => MarketplaceOrderStatus::Creating->value, 'message' => null]);

        if ($claimed === 0) {
            return;
        }

        try {
            $created = $client->createOrder($payload);
        } catch (WooCommerceRateLimitException $exception) {
            $row->update(['status' => MarketplaceOrderStatus::Pending]);

            throw $exception;
        } catch (WooCommerceRequestException $exception) {
            $row->update($exception->getPrevious() instanceof ConnectionException
                ? ['status' => MarketplaceOrderStatus::Creating, 'message' => __('WooCommerce did not answer. Check whether the order was created before importing it again.')]
                : ['status' => MarketplaceOrderStatus::Failed, 'message' => $exception->getMessage()]);

            return;
        } catch (WooCommerceException $exception) {
            $row->update(['status' => MarketplaceOrderStatus::Failed, 'message' => $exception->userMessage()]);

            return;
        }

        $row->update([
            'status' => MarketplaceOrderStatus::Imported,
            'woocommerce_order_id' => $created['id'],
            'imported_at' => now(),
            'message' => null,
        ]);

        try {
            $client->addOrderNote($created['id'], $note);
        } catch (WooCommerceException $exception) {
            report($exception);
        }
    }
}
