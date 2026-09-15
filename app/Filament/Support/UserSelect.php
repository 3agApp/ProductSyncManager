<?php

namespace App\Filament\Support;

use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class UserSelect
{
    /**
     * A select that searches users by name or email, so it stays fast however
     * many users there are.
     *
     * @param  (Closure(Builder<User>): mixed)|null  $scope  Narrows which users can be picked.
     */
    public static function make(string $name, ?Closure $scope = null): Select
    {
        return Select::make($name)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => User::query()
                ->when($scope, fn (Builder $query) => $scope($query))
                ->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->orderBy('email')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"])
                ->all())
            ->getOptionLabelUsing(function (mixed $value): ?string {
                $user = User::whereKey($value)->first();

                return $user ? "{$user->name} ({$user->email})" : null;
            });
    }
}
