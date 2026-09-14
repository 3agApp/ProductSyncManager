<?php

namespace App\Jobs;

use App\Actions\Nalda\BuildNaldaProductFeed;
use App\Models\NaldaExport;
use App\Services\Nalda\NaldaSftp;
use App\Services\Nalda\NaldaUploadException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class UploadNaldaProductFeed implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * Waiting for a running sync releases the job, which counts as an
     * attempt, so this is high and maxExceptions bounds real failures.
     */
    public int $tries = 120;

    /**
     * The number of seconds the job may run before timing out.
     */
    public int $timeout = 900;

    /**
     * The number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * How much of the file is held in memory before spilling to disk.
     */
    private const int MEMORY_BYTES = 8 * 1024 * 1024;

    /**
     * GTINs written to the listing table per insert.
     */
    private const int INSERT_SIZE = 500;

    public function __construct(public NaldaExport $export)
    {
        //
    }

    /**
     * Get the middleware the job should pass through.
     *
     * Sharing the sync's lock means a file is never built from a catalog a
     * sync is halfway through rewriting.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('shop-sync:'.$this->export->shop_id))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(3600),
        ];
    }

    /**
     * Get the number of seconds to wait before retrying.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Build the shop's product file and upload it to Nalda.
     */
    public function handle(BuildNaldaProductFeed $build, NaldaSftp $sftp): void
    {
        // A retry can arrive after the stalled-export reaper failed this one.
        if ($this->export->refresh()->status->isFinished()) {
            return;
        }

        $connection = $this->export->shop->naldaConnection;

        if ($connection === null || ! $connection->hasUsableCredentials()) {
            $this->fail(NaldaUploadException::authentication());

            return;
        }

        $this->export->markRunning();

        $stream = fopen('php://temp/maxmemory:'.self::MEMORY_BYTES, 'w+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for the Nalda product file.');
        }

        try {
            $feed = $build->handle($connection, $stream);
            rewind($stream);

            $fileName = $connection->file_name;

            $this->export->update([
                'file_name' => $fileName,
                'listed_products' => $feed->listed,
                'removed_products' => $feed->removed,
                'skipped_products' => $feed->skipped,
            ]);

            $sftp->upload($connection, $fileName, $stream);
        } catch (NaldaUploadException $exception) {
            if ($exception->isPermanent()) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } finally {
            fclose($stream);
        }

        // Only an upload that landed changes what Nalda lists, so the record
        // of listed GTINs is replaced after it and never before.
        DB::transaction(function () use ($feed) {
            $shopId = $this->export->shop_id;

            DB::table('nalda_listed_gtins')->where('shop_id', $shopId)->delete();

            foreach (array_chunk($feed->listedGtins, self::INSERT_SIZE) as $gtins) {
                DB::table('nalda_listed_gtins')->insert(array_map(
                    fn (string $gtin) => ['shop_id' => $shopId, 'gtin' => $gtin],
                    $gtins,
                ));
            }
        });

        $connection->update(['last_exported_at' => now()]);
        $this->export->markCompleted();
    }

    /**
     * Record the failure against the export so the user can see what happened.
     */
    public function failed(?Throwable $exception): void
    {
        $message = $exception instanceof NaldaUploadException
            ? $exception->userMessage()
            : __('The Nalda export failed unexpectedly. Please try again.');

        $this->export->refresh()->markFailed($message);
    }
}
