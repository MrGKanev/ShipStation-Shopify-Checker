<?php

use App\Http\Controllers\ActiveStoreController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderBatchLookupController;
use App\Http\Controllers\OrderComparisonController;
use App\Http\Controllers\OrderLookupController;
use App\Http\Controllers\OrderTagSearchController;
use App\Http\Controllers\OrderTimelineController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\PackingSlipController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/stores/{store}/active', ActiveStoreController::class)->name('stores.active');

    Route::middleware('active.store')->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/orders/lookup', OrderLookupController::class)->name('orders.lookup');
        Route::get('/orders/spot-check', [OrderBatchLookupController::class, 'create'])->name('orders.spot-check');
        Route::post('/orders/spot-check', [OrderBatchLookupController::class, 'store'])
            ->middleware('throttle:spot-check')
            ->name('orders.spot-check.store');
        Route::get('/orders/compare', OrderComparisonController::class)->name('orders.compare');
        Route::get('/orders/timeline', OrderTimelineController::class)->name('orders.timeline');
        Route::get('/orders/tracking', [OrderTrackingController::class, 'create'])->name('orders.tracking');
        Route::post('/orders/tracking', [OrderTrackingController::class, 'store'])->middleware('throttle:tracking')->name('orders.tracking.store');
        Route::get('/orders/packing-slip', [PackingSlipController::class, 'create'])->name('orders.packing-slip');
        Route::post('/orders/packing-slip', [PackingSlipController::class, 'store'])->middleware('throttle:packing-slip')->name('orders.packing-slip.store');
        Route::get('/orders/tag-search', [OrderTagSearchController::class, 'create'])->name('orders.tag-search');
        Route::post('/orders/tag-search', [OrderTagSearchController::class, 'store'])->middleware('throttle:tag-search')->name('orders.tag-search.store');

        Route::prefix('admin')
            ->name('admin.')
            ->middleware('can:manage-administration')
            ->group(function (): void {
                Route::resource('stores', StoreController::class)->except(['show', 'destroy']);
                Route::resource('users', UserController::class)->except(['show', 'destroy']);
            });
    });
});
