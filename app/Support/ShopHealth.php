<?php

namespace App\Support;

use App\Data\ShopPermissions;
use App\Enums\SyncRunStatus;
use App\Enums\TemuProductStatus;
use App\Models\NaldaExport;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\TemuSyncRun;

/**
 * What the shop settings page reports about a shop at a glance: the state of
 * each integration, and the short list of things someone has to act on.
 *
 * Both are worked out here rather than on the page so that "needs attention"
 * means the same thing everywhere it is shown.
 */
class ShopHealth
{
    public function __construct(
        private readonly Shop $shop,
        private readonly ShopPermissions $permissions,
    ) {}

    /**
     * The WooCommerce sync, Nalda and Temu, each with a one-word status and
     * a line of detail.
     *
     * @return array<int, array{key: string, status: string, statusLabel: string, detail: string|null}>
     */
    public function integrations(): array
    {
        if (! $this->permissions->canRunSync) {
            return [];
        }

        return [
            $this->integration(
                'sync',
                $this->shop->wooCommerceConnection?->hasUsableCredentials() ?? false,
                $this->latestSyncRun(),
                (string) __('Last sync'),
            ),
            $this->integration(
                'nalda',
                $this->shop->naldaConnection?->hasUsableCredentials() ?? false,
                $this->latestNaldaExport(),
                (string) __('Last upload'),
            ),
            $this->integration(
                'temu',
                $this->shop->temuConnection?->hasUsableCredentials() ?? false,
                $this->latestTemuRun(),
                (string) __('Last sync'),
            ),
        ];
    }

    /**
     * The things someone should act on, most urgent first.
     *
     * @return array<int, array{key: string, tone: string, title: string, description: string, target: string}>
     */
    public function attention(): array
    {
        $items = [];

        if ($this->permissions->canCreateInvitation || $this->permissions->canCancelInvitation) {
            $expired = $this->shop->invitations()
                ->whereNull('accepted_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->count();

            if ($expired > 0) {
                $items[] = [
                    'key' => 'expired-invitations',
                    'tone' => 'warning',
                    'title' => trans_choice('{1} An invitation has expired|[2,*] :count invitations have expired', $expired),
                    'description' => __('Resend it so the person can still join, or cancel it.'),
                    'target' => 'invitations',
                ];
            }
        }

        if (! $this->permissions->canRunSync) {
            return $items;
        }

        if ($this->latestSyncRun()?->status === SyncRunStatus::Failed) {
            $items[] = [
                'key' => 'sync-failed',
                'tone' => 'danger',
                'title' => __('The last WooCommerce sync failed'),
                'description' => $this->latestSyncRun()->message ?? __('Open the sync settings to see what went wrong.'),
                'target' => 'sync',
            ];
        }

        if ($this->latestNaldaExport()?->status === SyncRunStatus::Failed) {
            $items[] = [
                'key' => 'nalda-failed',
                'tone' => 'danger',
                'title' => __('The last Nalda upload failed'),
                'description' => $this->latestNaldaExport()->message ?? __('Open the Nalda settings to see what went wrong.'),
                'target' => 'nalda',
            ];
        }

        $temu = $this->shop->temuConnection;

        if ($temu?->tokenHasExpired()) {
            $items[] = [
                'key' => 'temu-token-expired',
                'tone' => 'danger',
                'title' => __('The Temu access token has expired'),
                'description' => __('Authorize the app again in Temu Seller Center and paste the new token.'),
                'target' => 'temu',
            ];
        }

        if ($this->latestTemuRun()?->status === SyncRunStatus::Failed) {
            $items[] = [
                'key' => 'temu-failed',
                'tone' => 'danger',
                'title' => __('The last Temu sync failed'),
                'description' => $this->latestTemuRun()->message ?? __('Open the Temu settings to see what went wrong.'),
                'target' => 'temu',
            ];
        }

        $problems = $this->shop->temuProducts()
            ->whereIn('status', array_map(
                fn (TemuProductStatus $status) => $status->value,
                array_filter(TemuProductStatus::cases(), fn (TemuProductStatus $status) => $status->needsAttention()),
            ))
            ->count();

        if ($problems > 0) {
            $items[] = [
                'key' => 'temu-products',
                'tone' => 'warning',
                'title' => trans_choice('{1} A product needs attention on Temu|[2,*] :count products need attention on Temu', $problems),
                'description' => __('Drafts, rejections and failed requests are listed on the Temu page.'),
                'target' => 'temu',
            ];
        }

        return $items;
    }

    /**
     * @param  SyncRun|NaldaExport|TemuSyncRun|null  $run
     * @return array{key: string, status: string, statusLabel: string, detail: string|null}
     */
    private function integration(string $key, bool $configured, $run, string $lastLabel): array
    {
        [$status, $label] = match (true) {
            ! $configured => ['not_configured', 'Not set up'],
            $run === null => ['connected', 'Connected'],
            $run->status->isActive() => ['running', 'Running'],
            $run->status === SyncRunStatus::Failed => ['failing', 'Failing'],
            default => ['connected', 'Connected'],
        };

        $when = $run !== null ? ($run->finished_at ?? $run->started_at ?? $run->created_at) : null;

        return [
            'key' => $key,
            'status' => $status,
            'statusLabel' => (string) __($label),
            'detail' => $configured && $when !== null
                ? "{$lastLabel} {$when->diffForHumans()}"
                : null,
        ];
    }

    private function latestSyncRun(): ?SyncRun
    {
        return once(fn () => $this->shop->syncRuns()->latest('id')->first());
    }

    private function latestNaldaExport(): ?NaldaExport
    {
        return once(fn () => $this->shop->naldaExports()->latest('id')->first());
    }

    private function latestTemuRun(): ?TemuSyncRun
    {
        return once(fn () => $this->shop->temuSyncRuns()->latest('id')->first());
    }
}
