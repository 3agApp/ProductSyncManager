<?php

namespace App\Actions\Nalda;

use App\Enums\NaldaDefaultBehavior;
use App\Enums\NaldaPriceMode;
use App\Models\NaldaConnection;
use App\Models\Shop;

class SaveNaldaConnection
{
    /**
     * Create or update a shop's Nalda settings.
     *
     * A blank password means "keep what is stored", so the settings form
     * never has to round trip it through the browser.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Shop $shop, array $data): NaldaConnection
    {
        $connection = $shop->naldaConnection ?? new NaldaConnection;
        $connection->shop_id = $shop->id;

        $username = trim((string) ($data['sftp_username'] ?? ''));
        $password = (string) ($data['sftp_password'] ?? '');

        $connection->sftp_username = $username === '' ? null : $username;

        if ($username === '') {
            $connection->sftp_password = null;
        } elseif ($password !== '') {
            $connection->sftp_password = $password;
        }

        // Any credential change invalidates the last successful test.
        if ($connection->isDirty(['sftp_username', 'sftp_password'])) {
            $connection->verified_at = null;
        }

        $connection->file_name = trim((string) ($data['file_name'] ?? '')) ?: NaldaConnection::DEFAULT_FILE_NAME;
        $connection->default_behavior = NaldaDefaultBehavior::from((string) $data['default_behavior']);
        $connection->country = strtoupper((string) $data['country']);
        $connection->condition = (string) $data['condition'];
        $connection->price_mode = NaldaPriceMode::from((string) $data['price_mode']);
        $connection->delivery_time_days = (int) $data['delivery_time_days'];
        $connection->return_days = (int) $data['return_days'];
        $connection->upload_after_sync = (bool) ($data['upload_after_sync'] ?? false);

        $connection->save();

        $shop->setRelation('naldaConnection', $connection);

        return $connection->refresh();
    }
}
