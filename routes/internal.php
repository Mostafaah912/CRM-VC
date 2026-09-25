<?php

declare(strict_types=1);

use App\Http\Controllers\Catalog\ProductListController;
use App\Http\Controllers\Customers\CustomerListController;
use App\Http\Controllers\Customers\CustomerNotesController;
use App\Http\Controllers\Customers\CustomerOrdersController;
use App\Http\Controllers\Customers\CustomerProductsController;
use App\Http\Controllers\Customers\CustomerShowController;
use App\Http\Controllers\Customers\CustomerTimelineController;
use App\Http\Controllers\Customers\PhoneRevealController;
use App\Http\Controllers\Metrics\RfmPageController;
use App\Http\Controllers\Orders\OrderListController;
use App\Http\Controllers\Orders\OrderShowController;
use App\Http\Controllers\Segments\SegmentPreviewController;
use Illuminate\Support\Facades\Route;

// PRD D15: internal routes — Inertia pages (and, later, internal JSON), never a public API. Every one needs a signed-in user
// AND a permission (closed by default). P3-01: the customer list. P3-03: one customer's 360 page (a soft-deleted customer is 404). P3-04: that customer's timeline, cursor-paged JSON.
Route::middleware(['auth', 'permission:customers,view'])->group(function () {
    Route::get('customers', CustomerListController::class)->name('customers.index');
    Route::get('customers/{customer}', CustomerShowController::class)->whereNumber('customer')->name('customers.show');
    Route::get('customers/{customer}/timeline', CustomerTimelineController::class)->whereNumber('customer')->name('customers.timeline');
    Route::get('customers/{customer}/orders', CustomerOrdersController::class)->whereNumber('customer')->name('customers.orders');
    Route::get('customers/{customer}/products', CustomerProductsController::class)->whereNumber('customer')->name('customers.products');
    Route::get('customers/{customer}/notes', [CustomerNotesController::class, 'index'])->whereNumber('customer')->name('customers.notes.index');
    // Deleting is allowed to the note's author or a customers.manage_notes holder; CustomerNotesService decides (403 otherwise). The
    // note is looked up under its customer, so a note id under another customer's URL is a 404.
    Route::delete('customers/{customer}/notes/{note}', [CustomerNotesController::class, 'destroy'])->whereNumber(['customer', 'note'])->name('customers.notes.destroy');
    // Writing a note also needs customers.note (the role matrix gives it to Support, Manager and Owner — not to a viewer-only Analyst).
    Route::post('customers/{customer}/notes', [CustomerNotesController::class, 'store'])->middleware('permission:customers,note')->whereNumber('customer')->name('customers.notes.store');
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

// P3-06: the order list and one order's detail — read-only, behind orders.view. Orders has no soft delete, so a missing id is
// simply a 404.
Route::middleware(['auth', 'permission:orders,view'])->group(function () {
    Route::get('orders', OrderListController::class)->name('orders.index');
    Route::get('orders/{order}', OrderShowController::class)->whereNumber('order')->name('orders.show');
});

// P3-07, Sprint 3's last task: the product list with lifetime sales — read-only, behind catalog.view.
Route::middleware(['auth', 'permission:catalog,view'])->group(function () {
    Route::get('products', ProductListController::class)->name('products.index');
});

// P4-08 part B: the RFM distribution page — read-only, behind metrics.view (existing since P0-05).
Route::middleware(['auth', 'permission:metrics,view'])->group(function () {
    Route::get('metrics/rfm', RfmPageController::class)->name('metrics.rfm');
});

// P5-05/P5-06: the Rule Builder's live preview count for a draft rule (not yet a saved Segment).
// Gated on segments.create OR segments.edit (EnsurePermission's OR form, P5-06) — either the create
// page or the edit page can use the same preview endpoint; each action is still independently
// resolved via deny>allow>role>default-deny.
Route::middleware(['auth', 'permission:segments,create,edit'])->group(function () {
    Route::post('segments/preview', SegmentPreviewController::class)->name('segments.preview');
});
