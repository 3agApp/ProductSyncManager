<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Shops\MarketplaceOrderController;
use App\Http\Controllers\Shops\NaldaController;
use App\Http\Controllers\Shops\ShopController;
use App\Http\Controllers\Shops\ShopInvitationController;
use App\Http\Controllers\Shops\ShopMemberController;
use App\Http\Controllers\Shops\ShopSyncController;
use App\Http\Controllers\Shops\SyncRunController;
use App\Http\Controllers\Shops\TemuController;
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

    // Appearance is a section of the profile page now. The old address
    // still works for bookmarks and links in sent mail.
    Route::redirect('settings/appearance', '/settings/profile#appearance')->name('appearance.edit');

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
        Route::post('settings/shops/{shop}/invitations/{invitation}/resend', [ShopInvitationController::class, 'resend'])
            ->middleware('throttle:6,1')
            ->name('shops.invitations.resend');

        Route::get('settings/shops/{shop}/sync', [ShopSyncController::class, 'edit'])->name('shops.sync.edit');
        Route::patch('settings/shops/{shop}/sync/connection', [ShopSyncController::class, 'updateConnection'])->name('shops.sync.connection.update');
        Route::patch('settings/shops/{shop}/sync/schedule', [ShopSyncController::class, 'updateSchedule'])->name('shops.sync.schedule.update');
        Route::post('settings/shops/{shop}/sync/webhooks', [ShopSyncController::class, 'setupWebhooks'])
            ->middleware('throttle:5,1')
            ->name('shops.sync.webhooks.store');
        Route::post('settings/shops/{shop}/sync/test', [ShopSyncController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('shops.sync.test');
        Route::post('settings/shops/{shop}/sync/runs', [SyncRunController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('shops.sync.runs.store');

        Route::get('settings/shops/{shop}/nalda', [NaldaController::class, 'edit'])->name('shops.nalda.edit');
        Route::patch('settings/shops/{shop}/nalda', [NaldaController::class, 'update'])->name('shops.nalda.update');
        Route::post('settings/shops/{shop}/nalda/test', [NaldaController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('shops.nalda.test');
        Route::post('settings/shops/{shop}/nalda/api-test', [NaldaController::class, 'testApi'])
            ->middleware('throttle:10,1')
            ->name('shops.nalda.api-test');
        Route::post('settings/shops/{shop}/nalda/exports', [NaldaController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('shops.nalda.exports.store');
        Route::get('settings/shops/{shop}/nalda/products.csv', [NaldaController::class, 'download'])
            ->middleware('throttle:20,1')
            ->name('shops.nalda.download');

        Route::get('settings/shops/{shop}/temu', [TemuController::class, 'edit'])->name('shops.temu.edit');
        Route::patch('settings/shops/{shop}/temu', [TemuController::class, 'update'])->name('shops.temu.update');
        Route::post('settings/shops/{shop}/temu/test', [TemuController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('shops.temu.test');
        Route::post('settings/shops/{shop}/temu/syncs', [TemuController::class, 'sync'])
            ->middleware('throttle:20,1')
            ->name('shops.temu.syncs.store');
        Route::post('settings/shops/{shop}/temu/retries', [TemuController::class, 'retry'])
            ->middleware('throttle:20,1')
            ->name('shops.temu.retries.store');
        Route::post('settings/shops/{shop}/marketplace-orders/{marketplaceOrder}/retry', [MarketplaceOrderController::class, 'retry'])
            ->middleware('throttle:20,1')
            ->scopeBindings()
            ->name('shops.marketplace-orders.retry');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
