<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Shops\ShopController;
use App\Http\Controllers\Shops\ShopInvitationController;
use App\Http\Controllers\Shops\ShopMemberController;
use App\Http\Controllers\Shops\ShopSyncController;
use App\Http\Controllers\Shops\SyncRunController;
use App\Http\Middleware\EnsureShopMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/shops', [ShopController::class, 'index'])->name('shops.index');
    Route::post('settings/shops', [ShopController::class, 'store'])->name('shops.store');

    Route::middleware(EnsureShopMembership::class)->group(function () {
        Route::get('settings/shops/{shop}', [ShopController::class, 'edit'])->name('shops.edit');
        Route::patch('settings/shops/{shop}', [ShopController::class, 'update'])->name('shops.update');
        Route::delete('settings/shops/{shop}', [ShopController::class, 'destroy'])->name('shops.destroy');
        Route::post('settings/shops/{shop}/switch', [ShopController::class, 'switch'])->name('shops.switch');
        Route::delete('settings/shops/{shop}/leave', [ShopController::class, 'leave'])->name('shops.leave');

        Route::patch('settings/shops/{shop}/members/{user}', [ShopMemberController::class, 'update'])->name('shops.members.update');
        Route::delete('settings/shops/{shop}/members/{user}', [ShopMemberController::class, 'destroy'])->name('shops.members.destroy');

        Route::post('settings/shops/{shop}/invitations', [ShopInvitationController::class, 'store'])->name('shops.invitations.store');
        Route::delete('settings/shops/{shop}/invitations/{invitation}', [ShopInvitationController::class, 'destroy'])->name('shops.invitations.destroy');

        Route::get('settings/shops/{shop}/sync', [ShopSyncController::class, 'edit'])->name('shops.sync.edit');
        Route::patch('settings/shops/{shop}/sync/connection', [ShopSyncController::class, 'updateConnection'])->name('shops.sync.connection.update');
        Route::patch('settings/shops/{shop}/sync/schedule', [ShopSyncController::class, 'updateSchedule'])->name('shops.sync.schedule.update');
        Route::post('settings/shops/{shop}/sync/test', [ShopSyncController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('shops.sync.test');
        Route::post('settings/shops/{shop}/sync/runs', [SyncRunController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('shops.sync.runs.store');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
