<?php

declare(strict_types=1);

use App\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:audit,view'])->group(function () {
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
});
