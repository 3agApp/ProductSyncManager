<?php

namespace App\Jobs;

use App\Actions\Nalda\BuildNaldaOrderStatusFile;
use App\Actions\Orders\RefreshMarketplaceOrderStatuses;
use App\Models\NaldaConnection;
use App\Models\Shop;
use App\Services\Nalda\NaldaSftp;
use App\Services\Nalda\NaldaUploadException;
use App\Services\WooCommerce\Exceptions\WooCommerceRateLimitException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Tell Nalda where its orders stand.
 *
 * The delivery details are read back from the store first, where the
 * Product Sync Manager plugin keeps them, and the order status file is then
 * uploaded to the shop's Nalda SFTP folder `order-status/`. This replaces the
 * old plugin's upload through the 3AG license server.
 */
class ExportNaldaOrderStatuses implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Where Nalda reads order status files from.
     */
    public const string FOLDER = 'order-status';

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Hold the unique lock at most this long, in case a worker dies mid-run.
     */
    public int $uniqueFor = 900;

    public function __construct(public int $shopId) {}

    /**
     * One job per shop.
     */
    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(RefreshMarketplaceOrderStatuses $refresh, BuildNaldaOrderStatusFile $build, NaldaSftp $sftp): void
    {
        $shop = Shop::find($this->shopId);
        $connection = $shop?->naldaConnection;

        if ($shop === null || $connection === null || ! self::canExport($connection)) {
            return;
        }

        try {
            $refresh->handle($shop);
        } catch (WooCommerceRateLimitException $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        $file = $build->handle($connection);

        if ($file['rows'] === 0) {
            return;
        }

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for the Nalda status file.');
        }

        fwrite($stream, $file['content']);
        rewind($stream);

        try {
            $sftp->upload($connection, self::FOLDER.'/order_status_'.now()->format('Y-m-d').'.csv', $stream);
        } catch (NaldaUploadException $exception) {
            $this->fail($exception);

            return;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $connection->update(['statuses_exported_at' => now()]);
    }

    /**
     * Determine if a shop's order statuses can be sent to Nalda.
     *
     * Statuses only exist for orders this app imported, so it follows the
     * same switch, and the upload needs the SFTP login.
     */
    public static function canExport(NaldaConnection $connection): bool
    {
        return ImportNaldaOrders::canImport($connection) && $connection->hasUsableCredentials();
    }
}
