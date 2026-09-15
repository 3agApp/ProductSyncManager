<?php

namespace App\Filament\Resources\Shops;

use App\Filament\Resources\Shops\Pages\EditShop;
use App\Filament\Resources\Shops\Pages\ListShops;
use App\Filament\Resources\Shops\Pages\ViewShop;
use App\Filament\Resources\Shops\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Shops\Schemas\ShopForm;
use App\Filament\Resources\Shops\Schemas\ShopInfolist;
use App\Filament\Resources\Shops\Tables\ShopsTable;
use App\Models\Shop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    /**
     * The shop policy decides what members may do. The admin is rarely a
     * member, and only the admin can reach the panel, so it is not applied.
     */
    protected static bool $shouldSkipAuthorization = true;

    public static function form(Schema $schema): Schema
    {
        return ShopForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShopInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShopsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('ownerMembership.user')
            ->withCount(['memberships', 'products']);
    }

    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShops::route('/'),
            'view' => ViewShop::route('/{record}'),
            'edit' => EditShop::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
