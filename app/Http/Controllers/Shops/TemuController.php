<?php

namespace App\Http\Controllers\Shops;

use App\Actions\Temu\SaveTemuConnection;
use App\Actions\Temu\StartTemuSync;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Enums\TemuDefaultBehavior;
use App\Enums\TemuOrderStatus;
use App\Enums\TemuProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shops\SaveTemuConnectionRequest;
use App\Jobs\ImportTemuOrders;
use App\Models\Shop;
use App\Models\TemuConnection;
use App\Models\TemuOrder;
use App\Models\TemuProduct;
use App\Models\TemuSyncRun;
use App\Services\Temu\TemuClient;
use App\Services\Temu\TemuException;
use App\Support\TemuProblemReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TemuController extends Controller
{
    /**
     * The number of past runs shown in the history table.
     */
    private const int RECENT_RUNS = 10;

    /**
     * The number of products needing attention shown at once.
     */
    private const int PROBLEMS = 50;

    /**
     * The number of recent Temu orders shown.
     */
    private const int ORDERS = 20;

    /**
     * Show the Temu settings and listing status for the shop.
     */
    public function edit(Request $request, Shop $shop): Response
    {
        Gate::authorize('runSync', $shop);

        return Inertia::render('shops/temu', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
            ],
            'connection' => fn () => $this->connectionProps($shop->temuConnection),
            'statusCounts' => fn () => $this->statusCounts($shop),
            'problems' => fn () => $shop->temuProducts()
                ->whereIn('status', self::attentionStatuses())
                ->with('product:id,name,sku')
                ->latest('updated_at')
                ->limit(self::PROBLEMS)
                ->get()
                ->map(fn (TemuProduct $record) => [
                    'id' => $record->id,
                    'productId' => $record->product_id,
                    'productName' => $record->product->name ?? __('Deleted product'),
                    'sku' => $record->product->sku ?? $record->external_sku_id,
                    'status' => $record->status->value,
                    'statusLabel' => $record->status->label(),
                    'temuStatus' => TemuProductStatus::describeTemuSubStatus($record->temu_sub_status),
                    'goodsId' => $record->goods_id,
                    'message' => $record->message,
                    'checkedAtDiff' => $record->checked_at?->diffForHumans(),
                    'canRetry' => $record->canRetry(),
                    'reason' => TemuProblemReason::for($record),
                ])
                ->all(),
            'problemsTotal' => fn () => $shop->temuProducts()
                ->whereIn('status', self::attentionStatuses())
                ->count(),
            'currentRun' => fn () => $this->runProps($shop->temuSyncRuns()->latest('id')->first()),
            'recentRuns' => fn () => $shop->temuSyncRuns()
                ->latest('id')
                ->limit(self::RECENT_RUNS)
                ->get()
                ->map(fn (TemuSyncRun $run) => $this->runProps($run))
                ->all(),
            'orders' => fn () => $this->orderProps($shop),
            'behaviors' => TemuDefaultBehavior::options(),
            'permissions' => $request->user()->toShopPermissions($shop),
        ]);
    }

    /**
     * Save the shop's Temu settings.
     */
    public function update(SaveTemuConnectionRequest $request, SaveTemuConnection $save, Shop $shop): RedirectResponse
    {
        $save->handle($shop, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Temu settings saved.')]);

        return back();
    }

    /**
     * Check the saved credentials against Temu with a read-only call.
     *
     * Temu reports which store the token belongs to and when it expires,
     * which is kept so the page can warn before the token runs out.
     */
    public function test(Request $request, Shop $shop): RedirectResponse
    {
        Gate::authorize('manageSync', $shop);

        $connection = $shop->temuConnection;

        if ($connection === null || ! $connection->hasUsableCredentials()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the app key, secret and access token first.')]);

            return back();
        }

        try {
            $result = (new TemuClient($connection))->call('bg.open.accesstoken.info.get');
        } catch (TemuException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->userMessage()]);

            return back();
        }

        $expires = $result['expiredTime'] ?? null;

        $connection->update([
            'verified_at' => now(),
            'mall_id' => is_numeric($result['mallId'] ?? null) ? (int) $result['mallId'] : $connection->mall_id,
            'token_expires_at' => is_numeric($expires) ? Carbon::createFromTimestamp((int) $expires) : $connection->token_expires_at,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected to Temu.')]);

        return back();
    }

    /**
     * Queue a sync of the shop's products to Temu.
     */
    public function sync(Request $request, StartTemuSync $start, Shop $shop): RedirectResponse
    {
        Gate::authorize('runSync', $shop);

        if (! $start->canSync($shop)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the Temu app key, secret and access token before syncing.')]);

            return back();
        }

        $run = $start->handle($shop, SyncTrigger::Manual, $request->user());

        Inertia::flash('toast', $run === null
            ? ['type' => 'error', 'message' => __('A Temu sync is already in progress.')]
            : ['type' => 'success', 'message' => __('Temu sync queued.')]);

        return back();
    }

    /**
     * Send products Temu refused again, one or every one of them.
     *
     * The products are put back to pending, which the next run creates
     * like any new product, and a run is queued straight away. If one is
     * already going, the products wait for the next.
     */
    public function retry(Request $request, StartTemuSync $start, Shop $shop): RedirectResponse
    {
        Gate::authorize('runSync', $shop);

        $validated = $request->validate([
            'record' => ['sometimes', 'integer'],
            'records' => ['sometimes', 'array', 'max:500'],
            'records.*' => ['integer'],
        ]);

        // One product, a group of them, or every failed one when neither
        // is given.
        $only = isset($validated['record']) ? [$validated['record']] : ($validated['records'] ?? null);

        $retried = $shop->temuProducts()
            ->where('status', TemuProductStatus::Failed->value)
            ->whereNull('goods_id')
            ->when($only !== null, fn ($query) => $query->whereKey($only))
            ->update(['status' => TemuProductStatus::Pending->value]);

        if ($retried === 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is nothing to retry.')]);

            return back();
        }

        $run = $start->canSync($shop) ? $start->handle($shop, SyncTrigger::Manual, $request->user()) : null;
        $sending = $run !== null && $run->status !== SyncRunStatus::Failed;

        Inertia::flash('toast', ['type' => 'success', 'message' => ! $sending
            ? trans_choice('{1} The product will be sent again on the next sync.|[2,*] :count products will be sent again on the next sync.', $retried)
            : trans_choice('{1} Sending the product to Temu again.|[2,*] Sending :count products to Temu again.', $retried)]);

        return back();
    }

    /**
     * Import a Temu order into WooCommerce again.
     *
     * Only an order that failed, or whose import was cut off, can be sent
     * again. For a cut-off one the page asks the person to check WooCommerce
     * first, since the order may already be there.
     */
    public function retryOrder(Shop $shop, TemuOrder $temuOrder): RedirectResponse
    {
        Gate::authorize('manageSync', $shop);

        if (! $temuOrder->status->needsAttention()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is nothing to retry.')]);

            return back();
        }

        $temuOrder->update(['status' => TemuOrderStatus::Pending, 'message' => null]);

        $connection = $shop->temuConnection;

        if ($connection !== null && ImportTemuOrders::canImport($connection)) {
            ImportTemuOrders::dispatch($shop->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The order will be imported again.')]);

        return back();
    }

    /**
     * Build the props for the recent Temu orders.
     *
     * Orders that need attention come first, however old they are.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderProps(Shop $shop): array
    {
        $storeUrl = $shop->wooCommerceConnection?->store_url;

        return $shop->temuOrders()
            ->orderByRaw('case when status in (?, ?) then 0 else 1 end', [TemuOrderStatus::Creating->value, TemuOrderStatus::Failed->value])
            ->latest('id')
            ->limit(self::ORDERS)
            ->get()
            ->map(fn (TemuOrder $order) => [
                'id' => $order->id,
                'parentOrderSn' => $order->parent_order_sn,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'temuStatus' => TemuOrder::describeTemuStatus($order->temu_status),
                'needsAttention' => $order->status->needsAttention(),
                'message' => $order->message,
                'orderedAt' => $order->ordered_at?->toISOString(),
                'shipByAt' => $order->ship_by_at?->toISOString(),
                'wooCommerceOrderId' => $order->woocommerce_order_id,
                'wooCommerceUrl' => $order->woocommerce_order_id !== null && $storeUrl !== null
                    ? rtrim($storeUrl, '/').'/wp-admin/post.php?post='.$order->woocommerce_order_id.'&action=edit'
                    : null,
            ])
            ->all();
    }

    /**
     * Build the connection props.
     *
     * The secret and token are never sent to the browser, only whether
     * they are stored.
     *
     * @return array<string, mixed>
     */
    private function connectionProps(?TemuConnection $connection): array
    {
        $gateway = (string) config('services.temu.base_url');

        if ($connection === null) {
            return [
                'isConfigured' => false,
                'gateway' => $gateway,
                'appKey' => '',
                'hasSecret' => false,
                'hasToken' => false,
                'isUsable' => false,
                'verifiedAt' => null,
                'mallId' => null,
                'tokenExpiresAt' => null,
                'tokenHasExpired' => false,
                'defaultBehavior' => TemuDefaultBehavior::ExcludeAll->value,
                'currency' => 'CHF',
                'discountPercent' => 10.0,
                'syncAfterSync' => true,
                'importOrders' => false,
                'orderShipping' => 5.5,
                'ordersCheckedAt' => null,
                'lastSyncedAt' => null,
            ];
        }

        $stored = rescue(fn () => [filled($connection->app_secret), filled($connection->access_token)], [true, true], report: false);

        return [
            'isConfigured' => true,
            'gateway' => $gateway,
            'appKey' => $connection->app_key ?? '',
            'hasSecret' => filled($connection->app_key) && $stored[0],
            'hasToken' => filled($connection->app_key) && $stored[1],
            'isUsable' => $connection->hasUsableCredentials(),
            'verifiedAt' => $connection->verified_at?->toISOString(),
            'mallId' => $connection->mall_id,
            'tokenExpiresAt' => $connection->token_expires_at?->toISOString(),
            'tokenHasExpired' => $connection->tokenHasExpired(),
            'defaultBehavior' => $connection->default_behavior->value,
            'currency' => $connection->currency,
            'discountPercent' => (float) $connection->discount_percent,
            'syncAfterSync' => $connection->sync_after_sync,
            'importOrders' => $connection->import_orders,
            'orderShipping' => (float) $connection->order_shipping,
            'ordersCheckedAt' => $connection->orders_checked_at?->toISOString(),
            'lastSyncedAt' => $connection->last_synced_at?->toISOString(),
        ];
    }

    /**
     * The statuses a person has to look at, as stored values.
     *
     * @return array<int, string>
     */
    private static function attentionStatuses(): array
    {
        return array_values(array_map(
            fn (TemuProductStatus $status) => $status->value,
            array_filter(TemuProductStatus::cases(), fn (TemuProductStatus $status) => $status->needsAttention()),
        ));
    }

    /**
     * Count the shop's Temu products in each status, leaving out empty ones.
     *
     * @return array<int, array{status: string, label: string, count: int}>
     */
    private function statusCounts(Shop $shop): array
    {
        $counts = $shop->temuProducts()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $rows = [];

        foreach (TemuProductStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);

            if ($count > 0) {
                $rows[] = ['status' => $status->value, 'label' => $status->label(), 'count' => $count];
            }
        }

        return $rows;
    }

    /**
     * Build the props for one run.
     *
     * @return array<string, mixed>|null
     */
    private function runProps(?TemuSyncRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'statusLabel' => $run->status->label(),
            'isActive' => $run->status->isActive(),
            'triggerLabel' => $run->trigger->label(),
            'createdProducts' => $run->created_products,
            'updatedProducts' => $run->updated_products,
            'removedProducts' => $run->removed_products,
            'failedProducts' => $run->failed_products,
            'skippedProducts' => $run->skipped_products,
            'message' => $run->message,
            'durationInSeconds' => $run->durationInSeconds(),
            'createdAtDiff' => $run->created_at?->diffForHumans(),
        ];
    }
}
