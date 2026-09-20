<?php

declare(strict_types=1);

use App\Http\Controllers\Customers\CustomerListController;
use Illuminate\Support\Facades\Route;

// PRD D15: internal routes — Inertia pages (and, later, internal JSON), never a public API. Every one needs a signed-in user
// AND a permission (closed by default). P3-01: the customer list.
Route::middleware(['auth', 'permission:customers,view'])->group(function () {
    Route::get('customers', CustomerListController::class)->name('customers.index');
});
