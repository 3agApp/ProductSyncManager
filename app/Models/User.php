<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasShops;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $sso_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property int|null $current_shop_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop|null $currentShop
 * @property-read Collection<int, Shop> $ownedShops
 * @property-read Collection<int, Membership> $shopMemberships
 * @property-read Collection<int, Shop> $shops
 */
#[Fillable(['name', 'email', 'password', 'current_shop_id', 'sso_id'])]
// The two_factor_* columns are dropped, so these three entries match nothing
// and cost nothing. They stay as a backstop: every page shares this model as
// the 'auth.user' prop, and a database that still has the columns -- one mid
// -deploy, or restored from a backup taken before the drop -- would otherwise
// put an encrypted 2FA secret back in the page JSON.
#[Hidden(['password', 'remember_token', 'sso_id', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasShops, Notifiable;

    /**
     * Determine if the user is the admin named by the ADMIN_EMAIL setting.
     */
    public function isAdmin(): bool
    {
        $email = config('admin.email');

        return filled($email) && strcasecmp($this->email, $email) === 0;
    }

    /**
     * Determine if the user can open the given Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
