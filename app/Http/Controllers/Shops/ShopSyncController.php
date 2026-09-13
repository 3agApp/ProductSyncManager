<?php

namespace App\Http\Controllers\Shops;

use App\Actions\Sync\SaveShopSyncSettings;
use App\Actions\Sync\SaveWooCommerceConnection;
use App\Enums\SyncFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shops\SaveShopSyncSettingsRequest;
use App\Http\Requests\Shops\SaveWooCommerceConnectionRequest;
use App\Http\Requests\Shops\TestWooCommerceConnectionRequest;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceClient;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ShopSyncController extends Controller
{
    /**
     * The number of past runs shown in the history table.
     */
    private const int RECENT_RUNS = 10;

    /**
     * Show the sync settings for the shop.
     */
    public function edit(Request $request, Shop $shop): Response
    {
        Gate::authorize('runSync', $shop);

        $user = $request->user();

        return Inertia::render('shops/sync', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'isPersonal' => $shop->is_personal,
                'type' => $shop->type->value,
                'typeLabel' => $shop->type->label(),
            ],
            'supportsSync' => $shop->type->supportsSync(),
            'connection' => fn () => $this->connectionProps($shop),
            'settings' => fn () => $this->settingsProps($shop),
            'currentRun' => fn () => $this->runProps($shop->syncRuns()->latest('id')->first()),
            'recentRuns' => fn () => $shop->syncRuns()
                ->latest('id')
                ->limit(self::RECENT_RUNS)
                ->get()
                ->map(fn (SyncRun $run) => $this->runProps($run))
                ->all(),
            'frequencies' => SyncFrequency::options(),
            'timezones' => fn () => DateTimeZone::listIdentifiers(),
            'permissions' => $user->toShopPermissions($shop),
        ]);
    }

    /**
     * Save the shop's WooCommerce credentials.
     */
    public function updateConnection(SaveWooCommerceConnectionRequest $request, SaveWooCommerceConnection $save, Shop $shop): RedirectResponse
    {
        $save->handle($shop, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection saved.')]);

        return back();
    }

    /**
     * Save the shop's sync schedule.
     */
    public function updateSchedule(SaveShopSyncSettingsRequest $request, SaveShopSyncSettings $save, Shop $shop): RedirectResponse
    {
        $save->handle($shop, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sync schedule saved.')]);

        return back();
    }

    /**
     * Check that the submitted credentials can reach the store.
     */
    public function test(TestWooCommerceConnectionRequest $request, Shop $shop): RedirectResponse
    {
        $stored = $shop->wooCommerceConnection;

        $connection = new WooCommerceConnection;
        $connection->store_url = (string) $request->validated('store_url');
        $connection->consumer_key = (string) ($request->validated('consumer_key') ?: $stored?->consumer_key);
        $connection->consumer_secret = (string) ($request->validated('consumer_secret') ?: $stored?->consumer_secret);

        if ($connection->consumer_key === '' || $connection->consumer_secret === '') {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Enter a consumer key and secret first.')]);

            return back();
        }

        try {
            $count = WooCommerceClient::quick($connection)->ping();
        } catch (WooCommerceException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->userMessage()]);

            return back();
        }

        $stored?->update(['verified_at' => now()]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Connected. Found :count importable products.', ['count' => number_format($count)]),
        ]);

        return back();
    }

    /**
     * Build the masked connection props.
     *
     * The stored secret is never sent to the browser, only its last four
     * characters, which is enough for the user to recognise which key is in
     * use without exposing it.
     *
     * @return array<string, mixed>|null
     */
    private function connectionProps(Shop $shop): ?array
    {
        $connection = $shop->wooCommerceConnection;

        if ($connection === null) {
            return null;
        }

        return [
            'webhookUrl' => route('woocommerce.webhook', ['connection' => $connection->id]),
            'webhookConfigured' => rescue(fn () => filled($connection->webhook_secret), false, report: false),
            'storeUrl' => $connection->store_url,
            'consumerKeyPreview' => $connection->keyPreview(),
            'consumerSecretPreview' => $connection->secretPreview(),
            'verifiedAt' => $connection->verified_at?->toISOString(),
            'isUsable' => $connection->hasUsableCredentials(),
        ];
    }

    /**
     * Build the schedule props, falling back to sensible defaults.
     *
     * @return array<string, mixed>
     */
    private function settingsProps(Shop $shop): array
    {
        $settings = $shop->syncSettings;

        if ($settings === null) {
            return [
                'isConfigured' => false,
                'isEnabled' => false,
                'frequency' => SyncFrequency::Daily->value,
                'timeOfDay' => '02:00',
                'dayOfWeek' => null,
                'timezone' => config('app.timezone'),
                'lastRunAt' => null,
                'nextRunAt' => null,
                'nextRunAtDiff' => null,
            ];
        }

        return [
            'isConfigured' => true,
            'isEnabled' => $settings->is_enabled,
            'frequency' => $settings->frequency->value,
            'timeOfDay' => $settings->time_of_day,
            'dayOfWeek' => $settings->day_of_week,
            'timezone' => $settings->timezone,
            'lastRunAt' => $settings->last_run_at?->toISOString(),
            'nextRunAt' => $settings->next_run_at?->toISOString(),
            'nextRunAtDiff' => $settings->next_run_at?->diffForHumans(),
        ];
    }

    /**
     * Build the props for one sync run.
     *
     * @return array<string, mixed>|null
     */
    private function runProps(?SyncRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'statusLabel' => $run->status->label(),
            'isActive' => $run->status->isActive(),
            'trigger' => $run->trigger->value,
            'triggerLabel' => $run->trigger->label(),
            'progress' => $run->progress(),
            'totalProducts' => $run->total_products,
            'processedProducts' => $run->processed_products,
            'createdProducts' => $run->created_products,
            'updatedProducts' => $run->updated_products,
            'archivedProducts' => $run->archived_products,
            'skippedProducts' => $run->skipped_products,
            'message' => $run->message,
            'durationInSeconds' => $run->durationInSeconds(),
            'startedAt' => $run->started_at?->toISOString(),
            'finishedAt' => $run->finished_at?->toISOString(),
            'createdAtDiff' => $run->created_at?->diffForHumans(),
        ];
    }
}
