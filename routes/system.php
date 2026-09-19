<?php

declare(strict_types=1);

use App\Http\Controllers\System\HealthController;
use App\Http\Controllers\System\IdentityConflictsController;
use App\Http\Controllers\System\SyncLogsController;
use Illuminate\Support\Facades\Route;

// P2-12: read-only system pages. Health and sync logs need system.view; the identity-conflict list needs identity.review.
Route::middleware(['auth', 'permission:system,view'])->prefix('system')->name('system.')->group(function () {
    Route::get('health', HealthController::class)->name('health');
    Route::get('sync-logs', SyncLogsController::class)->name('sync-logs');
});

Route::middleware(['auth', 'permission:identity,review'])->group(function () {
    Route::get('system/identity-conflicts', IdentityConflictsController::class)->name('system.identity-conflicts');
});
