<?php

namespace App\Filament\Resources\Shops\RelationManagers;

use App\Actions\Shops\TransferShopOwnership;
use App\Enums\ShopRole;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\UserSelect;
use App\Models\Membership;
use App\Models\Shop;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Members';

    /**
     * Members are managed from the shop's page as well as its edit page.
     */
    public function isReadOnly(): bool
    {
        return $this->shop()->trashed();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->url(fn (Membership $record): string => UserResource::getUrl('view', ['record' => $record->user_id])),
                TextColumn::make('user.email')
                    ->label('Email address'),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (ShopRole $state): string => $state === ShopRole::Owner ? 'primary' : 'gray')
                    ->formatStateUsing(fn (ShopRole $state): string => $state->label()),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime(),
            ])
            ->headerActions([
                Action::make('addMember')
                    ->label('Add member')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        UserSelect::make('user_id', fn (Builder $query) => $query
                            ->whereDoesntHave('shopMemberships', fn (Builder $memberships) => $memberships->where('shop_id', $this->shop()->id)))
                            ->label('User')
                            ->required(),
                        $this->roleSelect(),
                    ])
                    ->action(function (array $data): void {
                        $this->shop()->memberships()->firstOrCreate(
                            ['user_id' => $data['user_id']],
                            ['role' => ShopRole::from($data['role'])],
                        );

                        Notification::make()->title('Member added')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->hidden(fn (Membership $record): bool => $record->role === ShopRole::Owner)
                    ->fillForm(fn (Membership $record): array => ['role' => $record->role->value])
                    ->schema([
                        $this->roleSelect(),
                    ])
                    ->action(function (Membership $record, array $data): void {
                        $record->update(['role' => ShopRole::from($data['role'])]);

                        Notification::make()->title('Role updated')->success()->send();
                    }),
                Action::make('transferOwnership')
                    ->label('Make owner')
                    ->icon(Heroicon::OutlinedStar)
                    ->color('warning')
                    ->hidden(fn (Membership $record): bool => $record->role === ShopRole::Owner)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Membership $record): string => "{$record->user->email} becomes the owner. The current owner stays on as an admin.")
                    ->action(function (Membership $record): void {
                        app(TransferShopOwnership::class)->handle($this->shop(), $record->user);

                        Notification::make()->title('Ownership transferred')->success()->send();
                    }),
                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->hidden(fn (Membership $record): bool => $record->role === ShopRole::Owner)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Membership $record): string => "{$record->user->email} loses access to this shop.")
                    ->action(function (Membership $record): void {
                        $user = $record->user;

                        $record->delete();

                        if ($user->isCurrentShop($this->shop())) {
                            $user->switchToFallbackShop($this->shop());
                        }

                        Notification::make()->title('Member removed')->success()->send();
                    }),
            ]);
    }

    private function shop(): Shop
    {
        /** @var Shop */
        return $this->getOwnerRecord();
    }

    private function roleSelect(): Select
    {
        return Select::make('role')
            ->options([
                ShopRole::Admin->value => ShopRole::Admin->label(),
                ShopRole::Member->value => ShopRole::Member->label(),
            ])
            ->default(ShopRole::Member->value)
            ->required()
            ->native(false);
    }
}
