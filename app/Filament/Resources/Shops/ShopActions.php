<?php

namespace App\Filament\Resources\Shops;

use App\Actions\Shops\DeleteShop;
use App\Enums\ShopRole;
use App\Filament\Support\UserSelect;
use App\Models\Shop;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * The actions an admin can take on a shop, shared by its pages.
 */
class ShopActions
{
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Every member is removed and pending invitations are cancelled. The shop can be restored afterwards, with a new owner.')
            ->using(function (Shop $record): bool {
                app(DeleteShop::class)->handle($record);

                return true;
            });
    }

    /**
     * Restore a deleted shop. Deleting removed its members, so an owner has to
     * be chosen for it to be usable again.
     */
    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Shop $record): bool => $record->trashed())
            ->schema([
                UserSelect::make('owner_id')
                    ->label('Owner')
                    ->helperText('Deleting the shop removed its members, so choose who owns it now.')
                    ->required(),
            ])
            ->action(function (Shop $record, array $data): void {
                DB::transaction(function () use ($record, $data) {
                    $record->restore();

                    $record->memberships()->create([
                        'user_id' => $data['owner_id'],
                        'role' => ShopRole::Owner,
                    ]);
                });

                Notification::make()
                    ->title('Shop restored')
                    ->success()
                    ->send();
            });
    }
}
