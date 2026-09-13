<?php

namespace App\Http\Controllers\Shops;

use App\Actions\Sync\StartSyncRun;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class SyncRunController extends Controller
{
    /**
     * Queue a manual sync for the shop.
     */
    public function store(Request $request, StartSyncRun $startSyncRun, Shop $shop): RedirectResponse
    {
        Gate::authorize('runSync', $shop);

        if (! $startSyncRun->canSync($shop)) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Connect this shop to WooCommerce before syncing.'),
            ]);

            return back();
        }

        $run = $startSyncRun->handle($shop, SyncTrigger::Manual, $request->user());

        Inertia::flash('toast', match (true) {
            $run === null => ['type' => 'warning', 'message' => __('A sync is already running for this shop.')],
            $run->status === SyncRunStatus::Failed => ['type' => 'error', 'message' => $run->message],
            default => ['type' => 'success', 'message' => __('Sync started.')],
        });

        return back();
    }
}
