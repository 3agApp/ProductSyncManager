<?php

use App\Http\Controllers\Auth\AccountsSsoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Products\BulkProductController;
use App\Http\Controllers\Products\ProductController;
use App\Http\Controllers\Shops\ShopInvitationController;
use App\Http\Controllers\WooCommerceWebhookController;
use App\Http\Middleware\EnsureShopMembership;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetShopUrlDefaults;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// WooCommerce calls this, not a browser, and signs each delivery instead of
// carrying a CSRF token. Without a session, a burst of deliveries no longer
// writes one session per request.
Route::post('webhooks/woocommerce/{connection}', WooCommerceWebhookController::class)
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        HandleInertiaRequests::class,
        SetShopUrlDefaults::class,
    ])
    ->whereNumber('connection')
    ->name('woocommerce.webhook');

Route::inertia('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', LoginController::class)->name('login');
    Route::get('register', LoginController::class)->name('register');

    Route::get('auth/accounts/redirect', [AccountsSsoController::class, 'redirect'])
        ->name('auth.accounts.redirect');
    Route::get('auth/accounts/callback', [AccountsSsoController::class, 'callback'])
        ->name('auth.accounts.callback');
});

Route::post('logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

Route::delete('impersonation', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('impersonation.destroy');

Route::get('onboarding', OnboardingController::class)
    ->middleware(['auth', 'verified'])
    ->name('onboarding');

Route::prefix('{current_shop}')
    ->middleware(['auth', 'verified', EnsureShopMembership::class])
    ->scopeBindings()
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::post('products/bulk', BulkProductController::class)->name('products.bulk');
        Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::get('categories/{category}/deletion-preview', [CategoryController::class, 'deletionPreview'])->name('categories.deletion-preview');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
        Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
        Route::patch('brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('brands/{brand}', [BrandController::class, 'destroy'])->name('brands.destroy');
    });

Route::get('invitations', [ShopInvitationController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('invitations.index');

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [ShopInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [ShopInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
