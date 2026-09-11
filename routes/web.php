<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Shops\ShopInvitationController;
use App\Http\Middleware\EnsureShopMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::prefix('{current_shop}')
    ->middleware(['auth', 'verified', EnsureShopMembership::class])
    ->scopeBindings()
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [ShopInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [ShopInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
