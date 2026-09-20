<?php

namespace App\Filament\Resources\Users;

use App\Actions\Users\DeleteUser;
use App\Actions\Users\ResetTwoFactorAuthentication;
use App\Models\User;
use App\Support\Impersonation;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The actions an admin can take on a user, shared by the table and the
 * user's page.
 */
class UserActions
{
    public static function impersonate(): Action
    {
        return Action::make('impersonate')
            ->label('Impersonate')
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => "You will be signed in as {$record->email} until you choose \"Return to admin\".")
            ->hidden(fn (User $record): bool => $record->isAdmin())
            ->action(function (User $record): RedirectResponse {
                /** @var User $admin */
                $admin = Auth::user();

                app(Impersonation::class)->start($admin, $record);

                return redirect()->route('onboarding');
            });
    }

    public static function resetTwoFactor(): Action
    {
        return Action::make('resetTwoFactor')
            ->label('Reset 2FA & passkeys')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Turns off two-factor authentication and removes every passkey, so the user can sign in with just their password.')
            ->visible(fn (User $record): bool => $record->two_factor_confirmed_at !== null
                || $record->two_factor_secret !== null
                || $record->passkeys()->exists())
            ->action(function (User $record): void {
                app(ResetTwoFactorAuthentication::class)->handle($record);

                Notification::make()
                    ->title('Two-factor authentication and passkeys reset')
                    ->success()
                    ->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(function (User $record): string {
                $owned = $record->ownedShops()->count();

                return $owned > 0
                    ? "This also deletes the {$owned} ".str('shop')->plural($owned).' they own, for every member.'
                    : 'The user will be removed from every shop they belong to.';
            })
            ->hidden(fn (User $record): bool => $record->isAdmin())
            ->using(function (User $record): bool {
                app(DeleteUser::class)->handle($record);

                return true;
            });
    }
}
