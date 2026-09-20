<?php

declare(strict_types=1);

use App\Http\Controllers\Customers\CustomerListController;
use App\Http\Controllers\Customers\CustomerShowController;
use App\Http\Controllers\Customers\CustomerTimelineController;
use App\Http\Controllers\Customers\PhoneRevealController;
use Illuminate\Support\Facades\Route;

// PRD D15: internal routes — Inertia pages (and, later, internal JSON), never a public API. Every one needs a signed-in user
// AND a permission (closed by default). P3-01: the customer list. P3-03: one customer's 360 page (a soft-deleted customer is 404). P3-04: that customer's timeline, cursor-paged JSON.
Route::middleware(['auth', 'permission:customers,view'])->group(function () {
    Route::get('customers', CustomerListController::class)->name('customers.index');
    Route::get('customers/{customer}', CustomerShowController::class)->whereNumber('customer')->name('customers.show');
    Route::get('customers/{customer}/timeline', CustomerTimelineController::class)->whereNumber('customer')->name('customers.timeline');
});

// P3-02: the audited reveal of ONE customer's full phone. The list never carries a full number, for anyone; a holder of
// customers.view_full_phone asks for it here, one customer at a time: signed in, permitted, and at most ten a minute per user.
// Laravel runs the throttle before the permission check, so a refused viewer is throttled too — the endpoint cannot be probed.
// The throttle has its own bucket (the third parameter): an inline throttle without one is shared with every other
// inline-throttled route.
Route::middleware(['auth', 'permission:customers,view_full_phone', 'throttle:10,1,phone-reveal'])->group(function () {
    Route::post('customers/{customer}/reveal-phone', PhoneRevealController::class)
        ->whereNumber('customer')
        ->name('customers.reveal-phone');
});
