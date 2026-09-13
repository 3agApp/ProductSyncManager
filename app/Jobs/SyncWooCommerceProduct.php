<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Hand a delivery queued by an earlier release to the batched job.
 *
 * Webhooks used to queue one of these per delivery, each fetching its own
 * product under the shop's sync lock. Jobs already in a queue still carry
 * this class, so it stays until they have drained: each one now just makes
 * sure the connection's batched job is queued.
 *
 * @deprecated Use SyncWooCommerceWebhookDeliveries.
 */
class SyncWooCommerceProduct implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deliveryId, public int $shopId) {}

    public function handle(): void
    {
        $connectionId = DB::table('woocommerce_webhook_deliveries')->where('id', $this->deliveryId)->value('connection_id');

        if ($connectionId !== null) {
            SyncWooCommerceWebhookDeliveries::dispatch((int) $connectionId, $this->shopId);
        }
    }
}
