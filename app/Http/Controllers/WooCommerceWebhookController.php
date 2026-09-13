<?php

namespace App\Http\Controllers;

use App\Actions\Sync\ConfigureWooCommerceWebhooks;
use App\Jobs\SyncWooCommerceWebhookDeliveries;
use App\Models\WooCommerceConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WooCommerceWebhookController extends Controller
{
    public function __invoke(Request $request, WooCommerceConnection $connection): JsonResponse
    {
        abort_unless($connection->shop()->first()?->type->supportsSync(), 404);
        $secret = rescue(fn () => $connection->webhook_secret, null, report: false);
        abort_unless(filled($secret), 403);

        // WooCommerce's activation ping is unsigned and never changes data.
        if (! $request->hasHeader('X-WC-Webhook-Signature')
            && preg_match('/^webhook_id=[1-9][0-9]*$/D', $request->getContent())) {
            return response()->json(['status' => 'ok']);
        }

        $signature = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        abort_unless(hash_equals($signature, (string) $request->header('X-WC-Webhook-Signature')), 401);
        abort_unless(in_array($request->header('X-WC-Webhook-Topic'), ConfigureWooCommerceWebhooks::TOPICS, true), 422);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $remoteDelivery = (string) $request->header('X-WC-Webhook-Delivery-ID');
        abort_if($remoteDelivery === '' || strlen($remoteDelivery) > 255, 422);

        // Include the signed body so unsigned headers cannot suppress another event.
        $deliveryId = hash('sha256', $remoteDelivery.'|'.$signature);
        DB::table('woocommerce_webhook_deliveries')->insertOrIgnore([
            'connection_id' => $connection->id,
            'delivery_id' => $deliveryId,
            'product_id' => (int) $data['id'],
            'store_url' => $connection->store_url,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $delivery = DB::table('woocommerce_webhook_deliveries')
            ->where('connection_id', $connection->id)->where('delivery_id', $deliveryId)->first();

        // A dispatch failure returns 500; retrying the delivery can dispatch again.
        // The job is unique per connection and applies every pending delivery, so
        // a store saving its whole catalog queues one job, not one per product.
        if ($delivery->processed_at === null) {
            SyncWooCommerceWebhookDeliveries::dispatch($connection->id, $connection->shop_id);
        }

        return response()->json(['status' => 'accepted'], 202);
    }
}
