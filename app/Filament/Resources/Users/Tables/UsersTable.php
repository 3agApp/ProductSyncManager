<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean(),
                IconColumn::make('sso_id')
                    ->label('SSO linked')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shops_count')
                    ->label('Shops')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('email_verified_at')
                    ->label('Email verified')
                    ->nullable(),
                TernaryFilter::make('sso_id')
                    ->label('Linked to 3AG Accounts')
                    ->nullable(),
                Filter::make('without_shop')
                    ->label('Without a shop')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('shops')),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                    UserActions::impersonate(),
                    UserActions::delete(),
                ]),
            ]);
    }
}
