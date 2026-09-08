<?php

use App\Http\Controllers\ActiveStoreController;
use App\Http\Controllers\Admin\ApiHealthController;
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
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\Reports\AddressCheckController;
use App\Http\Controllers\Reports\CatalogQualityController;
use App\Http\Controllers\Reports\ConsentAuditController;
use App\Http\Controllers\Reports\CountryMismatchController;
use App\Http\Controllers\Reports\DiscountAbuseController;
use App\Http\Controllers\Reports\DisputeController;
use App\Http\Controllers\Reports\DuplicateAddressController;
use App\Http\Controllers\Reports\EmailCheckController;
use App\Http\Controllers\Reports\FraudRiskController;
use App\Http\Controllers\Reports\GiftCardsController;
use App\Http\Controllers\Reports\HighValueNoPhoneController;
use App\Http\Controllers\Reports\InventoryAgingController;
use App\Http\Controllers\Reports\InventoryForecastController;
use App\Http\Controllers\Reports\InventoryOversellController;
use App\Http\Controllers\Reports\ProductCompletenessController;
use App\Http\Controllers\Reports\SameIpController;
use App\Http\Controllers\Reports\SkuDuplicatesController;
use App\Http\Controllers\Reports\TagAuditController;
use App\Http\Controllers\Reports\TagPolicyController;
use App\Http\Controllers\Reports\TaxAuditController;
use App\Http\Controllers\Reports\ZombieProductsController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::get('/ready', ReadinessController::class)->name('ready');
Route::get('/status', StatusController::class)->name('status');

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
        Route::get('/reports/high-value-no-phone', [HighValueNoPhoneController::class, 'create'])->middleware('can:run-audits')->name('reports.high-value-no-phone');
        Route::post('/reports/high-value-no-phone', [HighValueNoPhoneController::class, 'store'])->middleware('throttle:audit-report')->name('reports.high-value-no-phone.store');
        Route::get('/reports/country-mismatch', [CountryMismatchController::class, 'create'])->middleware('can:run-audits')->name('reports.country-mismatch');
        Route::post('/reports/country-mismatch', [CountryMismatchController::class, 'store'])->middleware('throttle:audit-report')->name('reports.country-mismatch.store');
        Route::get('/reports/consent-audit', [ConsentAuditController::class, 'create'])->middleware('can:run-audits')->name('reports.consent-audit');
        Route::post('/reports/consent-audit', [ConsentAuditController::class, 'store'])->middleware('throttle:audit-report')->name('reports.consent-audit.store');
        Route::get('/reports/fraud-risk', [FraudRiskController::class, 'create'])->middleware('can:run-audits')->name('reports.fraud-risk');
        Route::post('/reports/fraud-risk', [FraudRiskController::class, 'store'])->middleware('throttle:audit-report')->name('reports.fraud-risk.store');
        Route::get('/reports/email-check', [EmailCheckController::class, 'create'])->middleware('can:run-audits')->name('reports.email-check');
        Route::post('/reports/email-check', [EmailCheckController::class, 'store'])->middleware('throttle:audit-report')->name('reports.email-check.store');
        Route::get('/reports/address-check', [AddressCheckController::class, 'create'])->middleware('can:run-audits')->name('reports.address-check');
        Route::post('/reports/address-check', [AddressCheckController::class, 'store'])->middleware('throttle:audit-report')->name('reports.address-check.store');
        Route::get('/reports/discount-abuse', [DiscountAbuseController::class, 'create'])->middleware('can:run-audits')->name('reports.discount-abuse');
        Route::post('/reports/discount-abuse', [DiscountAbuseController::class, 'store'])->middleware('throttle:audit-report')->name('reports.discount-abuse.store');
        Route::get('/reports/same-ip', [SameIpController::class, 'create'])->middleware('can:run-audits')->name('reports.same-ip');
        Route::post('/reports/same-ip', [SameIpController::class, 'store'])->middleware('throttle:audit-report')->name('reports.same-ip.store');
        Route::get('/reports/tag-policy', [TagPolicyController::class, 'create'])->middleware('can:run-audits')->name('reports.tag-policy');
        Route::post('/reports/tag-policy', [TagPolicyController::class, 'store'])->middleware('throttle:audit-report')->name('reports.tag-policy.store');
        Route::get('/reports/disputes', [DisputeController::class, 'create'])->middleware('can:run-audits')->name('reports.disputes');
        Route::post('/reports/disputes', [DisputeController::class, 'store'])->middleware('throttle:audit-report')->name('reports.disputes.store');
        Route::get('/reports/duplicate-addresses', [DuplicateAddressController::class, 'create'])->middleware('can:run-audits')->name('reports.duplicate-addresses');
        Route::post('/reports/duplicate-addresses', [DuplicateAddressController::class, 'store'])->middleware('throttle:audit-report')->name('reports.duplicate-addresses.store');
        Route::get('/reports/tag-audit', [TagAuditController::class, 'create'])->middleware('can:run-audits')->name('reports.tag-audit');
        Route::post('/reports/tag-audit', [TagAuditController::class, 'store'])->middleware('throttle:audit-report')->name('reports.tag-audit.store');
        Route::get('/reports/tax-audit', [TaxAuditController::class, 'create'])->middleware('can:run-audits')->name('reports.tax-audit');
        Route::post('/reports/tax-audit', [TaxAuditController::class, 'store'])->middleware('throttle:audit-report')->name('reports.tax-audit.store');
        Route::get('/reports/product-completeness', [ProductCompletenessController::class, 'create'])->middleware('can:run-audits')->name('reports.product-completeness');
        Route::post('/reports/product-completeness', [ProductCompletenessController::class, 'store'])->middleware('throttle:audit-report')->name('reports.product-completeness.store');

        Route::get('/reports/sku-duplicates', [SkuDuplicatesController::class, 'create'])->middleware('can:run-audits')->name('reports.sku-duplicates');
        Route::post('/reports/sku-duplicates', [SkuDuplicatesController::class, 'store'])->middleware('throttle:audit-report')->name('reports.sku-duplicates.store');
        Route::get('/reports/inventory-oversell', [InventoryOversellController::class, 'create'])->middleware('can:run-audits')->name('reports.inventory-oversell');
        Route::post('/reports/inventory-oversell', [InventoryOversellController::class, 'store'])->middleware('throttle:audit-report')->name('reports.inventory-oversell.store');
        Route::get('/reports/inventory-aging', [InventoryAgingController::class, 'create'])->middleware('can:run-audits')->name('reports.inventory-aging');
        Route::post('/reports/inventory-aging', [InventoryAgingController::class, 'store'])->middleware('throttle:audit-report')->name('reports.inventory-aging.store');
        Route::get('/reports/inventory-forecast', [InventoryForecastController::class, 'create'])->middleware('can:run-audits')->name('reports.inventory-forecast');
        Route::post('/reports/inventory-forecast', [InventoryForecastController::class, 'store'])->middleware('throttle:audit-report')->name('reports.inventory-forecast.store');
        Route::get('/reports/zombie-products', [ZombieProductsController::class, 'create'])->middleware('can:run-audits')->name('reports.zombie-products');
        Route::post('/reports/zombie-products', [ZombieProductsController::class, 'store'])->middleware('throttle:audit-report')->name('reports.zombie-products.store');
        Route::get('/reports/catalog-quality', [CatalogQualityController::class, 'create'])->middleware('can:run-audits')->name('reports.catalog-quality');
        Route::post('/reports/catalog-quality', [CatalogQualityController::class, 'store'])->middleware('throttle:audit-report')->name('reports.catalog-quality.store');
        Route::get('/reports/gift-cards', [GiftCardsController::class, 'create'])->middleware('can:run-audits')->name('reports.gift-cards');
        Route::post('/reports/gift-cards', [GiftCardsController::class, 'store'])->middleware('throttle:audit-report')->name('reports.gift-cards.store');

        Route::prefix('admin')
            ->name('admin.')
            ->middleware('can:manage-administration')
            ->group(function (): void {
                Route::get('/api-health', [ApiHealthController::class, 'show'])->name('api-health');
                Route::post('/api-health', [ApiHealthController::class, 'check'])->middleware('throttle:api-health')->name('api-health.check');
                Route::post('/api-health/test-email', [ApiHealthController::class, 'sendTestEmail'])->middleware('throttle:api-health')->name('api-health.test-email');
                Route::resource('stores', StoreController::class)->except(['show', 'destroy']);
                Route::resource('users', UserController::class)->except(['show', 'destroy']);
            });
    });
});
