<?php

use App\Enums\ShopRole;
use App\Filament\Resources\Shops\Pages\EditShop;
use App\Filament\Resources\Shops\Pages\ListShops;
use App\Filament\Resources\Shops\Pages\ViewShop;
use App\Filament\Resources\Shops\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Shops\ShopResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Shop;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

beforeEach(function () {
    config(['admin.email' => 'admin@example.com']);

    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
});

describe('access', function () {
    test('guests are sent to the app login', function () {
        $this->get('/admin')->assertRedirect(route('login'));
    });

    test('users other than the admin are forbidden', function () {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    });

    test('the admin can open every page', function () {
        $user = User::factory()->create();
        $shop = $user->currentShop;

        $this->actingAs($this->admin);

        $this->get('/admin')->assertOk();
        $this->get(UserResource::getUrl('index'))->assertOk();
        $this->get(UserResource::getUrl('view', ['record' => $user]))->assertOk();
        $this->get(UserResource::getUrl('edit', ['record' => $user]))->assertOk();
        $this->get(ShopResource::getUrl('index'))->assertOk();
        $this->get(ShopResource::getUrl('view', ['record' => $shop]))->assertOk();
        $this->get(ShopResource::getUrl('edit', ['record' => $shop]))->assertOk();
    });

    test('the admin email is matched case-insensitively', function () {
        config(['admin.email' => 'ADMIN@Example.com']);

        expect($this->admin->isAdmin())->toBeTrue();
    });

    test('nobody is the admin when no email is configured', function () {
        config(['admin.email' => null]);

        $this->actingAs($this->admin)->get('/admin')->assertForbidden();
    });

    test('the app tells the frontend who the admin is', function () {
        $this->actingAs($this->admin)
            ->get(route('dashboard', ['current_shop' => $this->admin->currentShop->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', true));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_shop' => $user->currentShop->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', false));
    });
});

describe('users', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    test('users can be listed and searched', function () {
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $bob = User::factory()->create(['email' => 'bob@example.com']);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$alice, $bob])
            ->searchTable('alice')
            ->assertCanSeeTableRecords([$alice])
            ->assertCanNotSeeTableRecords([$bob]);
    });

    test('a user can be edited without changing their password', function () {
        $user = User::factory()->unverified()->create();
        $password = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'name' => 'Renamed',
                'email' => 'renamed@example.com',
                'email_verified_at' => '2026-09-01 10:00:00',
                'password' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        expect($user->name)->toBe('Renamed')
            ->and($user->email)->toBe('renamed@example.com')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->password)->toBe($password);
    });

    test('a user can be given a new password', function () {
        $user = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['password' => 'a-new-Password-123'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Hash::check('a-new-Password-123', $user->fresh()->password))->toBeTrue();
    });

    test('deleting a user deletes the shops they own', function () {
        $user = User::factory()->create();
        $ownedShop = $user->currentShop;

        $member = User::factory()->create();
        $ownedShop->members()->attach($member, ['role' => ShopRole::Member->value]);
        $member->update(['current_shop_id' => $ownedShop->id]);

        $otherShop = Shop::factory()->create();
        $otherShop->members()->attach($user, ['role' => ShopRole::Member->value]);

        Livewire::test(ViewUser::class, ['record' => $user->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($user);
        $this->assertSoftDeleted($ownedShop);
        $this->assertNotSoftDeleted($otherShop);

        expect($member->fresh()->current_shop_id)->toBe($member->ownedShops()->first()->id);
    });

    test('the admin cannot delete or impersonate themselves', function () {
        Livewire::test(ViewUser::class, ['record' => $this->admin->getKey()])
            ->assertActionHidden(DeleteAction::class)
            ->assertActionHidden('impersonate');
    });

    test('two-factor authentication and passkeys can be reset', function () {
        $user = User::factory()->withTwoFactor()->create();
        $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => 'credential-1',
            'credential' => ['id' => 'credential-1'],
        ]);

        Livewire::test(ViewUser::class, ['record' => $user->getKey()])
            ->callAction('resetTwoFactor');

        $user->refresh();

        expect($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_confirmed_at)->toBeNull()
            ->and($user->passkeys()->count())->toBe(0);
    });
});

describe('impersonation', function () {
    test('the admin can sign in as a user and return', function () {
        $user = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(ViewUser::class, ['record' => $user->getKey()])
            ->callAction('impersonate')
            ->assertRedirect(route('onboarding'));

        $this->assertAuthenticatedAs($user);

        $this->get(route('dashboard', ['current_shop' => $user->currentShop->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('impersonating', true)
                ->where('auth.user.id', $user->id)
                ->where('auth.isAdmin', false));

        $this->get('/admin')->assertForbidden();

        $this->delete(route('impersonation.destroy'))
            ->assertRedirect(UserResource::getUrl('view', ['record' => $user]));

        $this->assertAuthenticatedAs($this->admin);

        $this->get('/admin')->assertOk();
    });

    test('stopping without impersonating is forbidden', function () {
        $this->actingAs(User::factory()->create())
            ->delete(route('impersonation.destroy'))
            ->assertForbidden();
    });
});

describe('shops', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    test('shops can be listed', function () {
        $shops = Shop::factory()->count(2)->create();

        Livewire::test(ListShops::class)->assertCanSeeTableRecords($shops);
    });

    test('a shop can be edited', function () {
        $shop = User::factory()->create()->currentShop;

        Livewire::test(EditShop::class, ['record' => $shop->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed Shop',
                'currency' => 'eur',
                'tax_rate' => '7.7',
                'prices_include_tax' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop->refresh();

        expect($shop->name)->toBe('Renamed Shop')
            ->and($shop->slug)->toBe('renamed-shop')
            ->and($shop->currency)->toBe('EUR')
            ->and($shop->tax_rate)->toBe('7.70')
            ->and($shop->prices_include_tax)->toBeFalse();
    });

    test('a shop can be deleted and restored with a new owner', function () {
        $owner = User::factory()->withoutShop()->create();
        $shop = Shop::factory()->create();
        $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
        $owner->update(['current_shop_id' => $shop->id]);

        Livewire::test(ViewShop::class, ['record' => $shop->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertSoftDeleted($shop);
        expect($shop->memberships()->count())->toBe(0)
            ->and($owner->fresh()->current_shop_id)->toBeNull();

        $newOwner = User::factory()->create();

        Livewire::test(ViewShop::class, ['record' => $shop->getRouteKey()])
            ->callAction('restore', data: ['owner_id' => $newOwner->id])
            ->assertHasNoFormErrors();

        $this->assertNotSoftDeleted($shop);
        expect($newOwner->fresh()->ownsShop($shop))->toBeTrue();
    });

    test('members can be added, have their role changed and be removed', function () {
        $owner = User::factory()->create();
        $shop = $owner->currentShop;
        $user = User::factory()->create();

        $manager = Livewire::test(MembershipsRelationManager::class, [
            'ownerRecord' => $shop,
            'pageClass' => ViewShop::class,
        ]);

        $manager->callAction(TestAction::make('addMember')->table(), data: [
            'user_id' => $user->id,
            'role' => ShopRole::Member->value,
        ])->assertHasNoFormErrors();

        expect($user->shopRole($shop))->toBe(ShopRole::Member);

        $membership = $shop->memberships()->where('user_id', $user->id)->first();

        $manager->callAction(TestAction::make('changeRole')->table($membership), data: [
            'role' => ShopRole::Admin->value,
        ])->assertHasNoFormErrors();

        expect($user->shopRole($shop))->toBe(ShopRole::Admin);

        $user->update(['current_shop_id' => $shop->id]);

        $manager->callAction(TestAction::make('remove')->table($membership));

        expect($user->fresh()->belongsToShop($shop))->toBeFalse()
            ->and($user->fresh()->current_shop_id)->toBe($user->ownedShops()->first()->id);
    });

    test('the owner cannot be removed, only replaced', function () {
        $owner = User::factory()->create();
        $shop = $owner->currentShop;
        $admin = User::factory()->create();
        $shop->members()->attach($admin, ['role' => ShopRole::Admin->value]);

        $ownerMembership = $shop->ownerMembership;
        $adminMembership = $shop->memberships()->where('user_id', $admin->id)->first();

        Livewire::test(MembershipsRelationManager::class, [
            'ownerRecord' => $shop,
            'pageClass' => ViewShop::class,
        ])
            ->assertActionHidden(TestAction::make('remove')->table($ownerMembership))
            ->callAction(TestAction::make('transferOwnership')->table($adminMembership));

        expect($admin->shopRole($shop))->toBe(ShopRole::Owner)
            ->and($owner->shopRole($shop))->toBe(ShopRole::Admin);
    });
});
