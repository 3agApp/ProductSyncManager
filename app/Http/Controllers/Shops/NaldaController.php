<?php

namespace App\Http\Controllers\Shops;

use App\Actions\Nalda\BuildNaldaProductFeed;
use App\Actions\Nalda\SaveNaldaConnection;
use App\Actions\Nalda\StartNaldaExport;
use App\Enums\Marketplace;
use App\Enums\NaldaDefaultBehavior;
use App\Enums\NaldaPriceMode;
use App\Enums\SyncTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shops\SaveNaldaConnectionRequest;
use App\Http\Requests\Shops\TestNaldaConnectionRequest;
use App\Models\NaldaConnection;
use App\Models\NaldaExport;
use App\Models\Shop;
use App\Services\Nalda\NaldaApiException;
use App\Services\Nalda\NaldaClient;
use App\Services\Nalda\NaldaSftp;
use App\Services\Nalda\NaldaUploadException;
use App\Support\MarketplaceOrderList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NaldaController extends Controller
{
    /**
     * The number of past exports shown in the history table.
     */
    private const int RECENT_EXPORTS = 10;

    /**
     * Show the Nalda settings for the shop.
     */
    public function edit(Request $request, Shop $shop): Response
    {
        Gate::authorize('runSync', $shop);

        return Inertia::render('shops/nalda', [
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
            ],
            'connection' => fn () => $this->connectionProps($shop->naldaConnection),
            'catalog' => fn () => [
                'withGtin' => $shop->products()->whereNotNull('gtin')->where('gtin', '!=', '')->count(),
                'withoutGtin' => $shop->products()->where(fn ($query) => $query->whereNull('gtin')->orWhere('gtin', ''))->count(),
            ],
            'currentExport' => fn () => $this->exportProps($shop->naldaExports()->latest('id')->first()),
            'recentExports' => fn () => $shop->naldaExports()
                ->latest('id')
                ->limit(self::RECENT_EXPORTS)
                ->get()
                ->map(fn (NaldaExport $export) => $this->exportProps($export))
                ->all(),
            'orders' => fn () => MarketplaceOrderList::props($shop, Marketplace::Nalda),
            'behaviors' => NaldaDefaultBehavior::options(),
            'priceModes' => NaldaPriceMode::options(),
            'shopTax' => fn () => [
                'pricesIncludeTax' => $shop->prices_include_tax,
                'taxRate' => rtrim(rtrim($shop->tax_rate, '0'), '.'),
                'naldaTaxRate' => (new NaldaConnection)->setRelation('shop', $shop)->taxRate(),
            ],
            'permissions' => $request->user()->toShopPermissions($shop),
        ]);
    }

    /**
     * Save the shop's Nalda settings.
     */
    public function update(SaveNaldaConnectionRequest $request, SaveNaldaConnection $save, Shop $shop): RedirectResponse
    {
        $save->handle($shop, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Nalda settings saved.')]);

        return back();
    }

    /**
     * Check that the submitted credentials can log in to Nalda's SFTP server.
     */
    public function test(TestNaldaConnectionRequest $request, NaldaSftp $sftp, Shop $shop): RedirectResponse
    {
        $stored = $shop->naldaConnection;
        $username = trim((string) $request->validated('sftp_username'));

        $connection = new NaldaConnection;
        $connection->sftp_username = $username ?: $stored?->sftp_username;
        $connection->sftp_password = (string) $request->validated('sftp_password')
            ?: rescue(fn () => $stored?->sftp_password, null, report: false);

        if (! $connection->hasUsableCredentials()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Enter the SFTP user and password first.')]);

            return back();
        }

        try {
            $sftp->check($connection);
        } catch (NaldaUploadException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->userMessage()]);

            return back();
        }

        if ($stored !== null && $stored->sftp_username === $connection->sftp_username) {
            $stored->update(['verified_at' => now()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected to the Nalda SFTP server.')]);

        return back();
    }

    /**
     * Check the saved API key against Nalda with a read-only call.
     */
    public function testApi(Shop $shop): RedirectResponse
    {
        Gate::authorize('manageSync', $shop);

        $connection = $shop->naldaConnection;

        if ($connection === null || ! $connection->hasUsableApiKey()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the Nalda API key first.')]);

            return back();
        }

        try {
            (new NaldaClient($connection))->check();
        } catch (NaldaApiException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected to the Nalda API.')]);

        return back();
    }

    /**
     * Queue an upload of the product file to Nalda.
     */
    public function export(Request $request, StartNaldaExport $start, Shop $shop): RedirectResponse
    {
        Gate::authorize('runSync', $shop);

        if (! $start->canExport($shop)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the Nalda SFTP user and password before uploading.')]);

            return back();
        }

        $export = $start->handle($shop, SyncTrigger::Manual, $request->user());

        Inertia::flash('toast', $export === null
            ? ['type' => 'error', 'message' => __('An upload to Nalda is already in progress.')]
            : ['type' => 'success', 'message' => __('Upload to Nalda queued.')]);

        return back();
    }

    /**
     * Download the product file, for checking it or uploading it by hand.
     */
    public function download(Shop $shop, BuildNaldaProductFeed $build): StreamedResponse|RedirectResponse
    {
        Gate::authorize('runSync', $shop);

        $connection = $shop->naldaConnection;

        if ($connection === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the Nalda settings before downloading the product file.')]);

            return back();
        }

        $fileName = 'nalda-products-'.$shop->slug.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($build, $connection) {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                throw new RuntimeException('Could not open the output stream for the Nalda product file.');
            }

            $build->handle($connection, $stream);

            fclose($stream);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Build the connection props, falling back to the defaults Nalda sellers in
     * Switzerland start from.
     *
     * The password is never sent to the browser, only whether one is stored.
     *
     * @return array<string, mixed>
     */
    private function connectionProps(?NaldaConnection $connection): array
    {
        $sftp = [
            'sftpHost' => config('services.nalda.sftp_host'),
            'sftpPort' => (int) config('services.nalda.sftp_port'),
        ];

        if ($connection === null) {
            return [
                ...$sftp,
                'isConfigured' => false,
                'sftpUsername' => '',
                'hasPassword' => false,
                'isUsable' => false,
                'verifiedAt' => null,
                'fileName' => NaldaConnection::DEFAULT_FILE_NAME,
                'defaultBehavior' => NaldaDefaultBehavior::IncludeAll->value,
                'country' => 'CH',
                'currency' => 'CHF',
                'condition' => 'new',
                'priceMode' => NaldaPriceMode::AsIs->value,
                'deliveryTimeDays' => 3,
                'returnDays' => 14,
                'uploadAfterSync' => true,
                'lastExportedAt' => null,
                'hasApiKey' => false,
                'importOrders' => false,
                'ordersCheckedAt' => null,
                'statusesExportedAt' => null,
            ];
        }

        return [
            ...$sftp,
            'isConfigured' => true,
            'sftpUsername' => $connection->sftp_username ?? '',
            'hasPassword' => filled($connection->sftp_username),
            'isUsable' => $connection->hasUsableCredentials(),
            'verifiedAt' => $connection->verified_at?->toISOString(),
            'fileName' => $connection->file_name,
            'defaultBehavior' => $connection->default_behavior->value,
            'country' => $connection->country,
            'currency' => $connection->currency,
            'condition' => $connection->condition,
            'priceMode' => $connection->price_mode->value,
            'deliveryTimeDays' => $connection->delivery_time_days,
            'returnDays' => $connection->return_days,
            'uploadAfterSync' => $connection->upload_after_sync,
            'lastExportedAt' => $connection->last_exported_at?->toISOString(),
            'hasApiKey' => $connection->hasUsableApiKey(),
            'importOrders' => $connection->import_orders,
            'ordersCheckedAt' => $connection->orders_checked_at?->toISOString(),
            'statusesExportedAt' => $connection->statuses_exported_at?->toISOString(),
        ];
    }

    /**
     * Build the props for one export.
     *
     * @return array<string, mixed>|null
     */
    private function exportProps(?NaldaExport $export): ?array
    {
        if ($export === null) {
            return null;
        }

        return [
            'id' => $export->id,
            'status' => $export->status->value,
            'statusLabel' => $export->status->label(),
            'isActive' => $export->status->isActive(),
            'triggerLabel' => $export->trigger->label(),
            'listedProducts' => $export->listed_products,
            'removedProducts' => $export->removed_products,
            'skippedProducts' => $export->skipped_products,
            'fileName' => $export->file_name,
            'message' => $export->message,
            'durationInSeconds' => $export->durationInSeconds(),
            'createdAtDiff' => $export->created_at?->diffForHumans(),
        ];
    }
}
