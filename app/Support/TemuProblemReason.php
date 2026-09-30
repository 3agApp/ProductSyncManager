<?php

namespace App\Support;

use App\Enums\TemuProductStatus;
use App\Models\TemuProduct;

/**
 * Why a product needs attention on Temu, in words that say what to do.
 *
 * Temu's own messages name the symptom ("No categories are recommended")
 * rather than the fix, and the same one comes back for many products at
 * once, so the Temu page groups products by this reason and shows the fix
 * once per group.
 */
class TemuProblemReason
{
    /**
     * @return array{key: string, title: string, hint: string|null, fix: 'title'|'retry'|'seller_center'|null}
     */
    public static function for(TemuProduct $record): array
    {
        $message = trim((string) $record->message);

        if (preg_match('/no categor(y|ies) (is|are) recommended/i', $message) === 1) {
            return [
                'key' => 'no-category',
                'title' => __('Temu could not find a category from the title'),
                'hint' => __('Temu picks the category from the product title. Give these products a more descriptive title on Temu, then retry.'),
                'fix' => 'title',
            ];
        }

        if (preg_match('/system error|try again later|time ?out|busy/i', $message) === 1) {
            return [
                'key' => 'temu-error',
                'title' => __('Temu had a temporary error'),
                'hint' => __('Nothing is wrong with these products. Retry them now, or leave them for the next sync.'),
                'fix' => 'retry',
            ];
        }

        if ($record->status === TemuProductStatus::Draft) {
            return [
                'key' => 'draft',
                'title' => __('Created on Temu but not submitted'),
                'hint' => __('Finish these listings in Temu Seller Center to put them up for sale.'),
                'fix' => 'seller_center',
            ];
        }

        if ($record->status === TemuProductStatus::Rejected) {
            $detail = $message !== '' ? $message : TemuProductStatus::describeTemuSubStatus($record->temu_sub_status);

            return [
                'key' => 'rejected:'.($detail ?? ''),
                'title' => $detail !== null ? __('Rejected by Temu: :detail', ['detail' => $detail]) : __('Rejected by Temu'),
                'hint' => __('Check what Temu asks for in Seller Center, change the product, and the next sync sends it again.'),
                'fix' => 'seller_center',
            ];
        }

        // Anything else is shown as Temu said it, grouped with every other
        // product that got the same answer.
        $detail = $message !== ''
            ? $message
            : (TemuProductStatus::describeTemuSubStatus($record->temu_sub_status) ?? $record->status->label());

        return [
            'key' => 'other:'.$detail,
            'title' => $detail,
            'hint' => null,
            'fix' => $record->canRetry() ? 'retry' : null,
        ];
    }
}
