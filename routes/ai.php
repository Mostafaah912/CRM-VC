<?php

declare(strict_types=1);

use App\Http\Controllers\Ai\AnalystGenerateController;
use App\Http\Controllers\Ai\AnalystHistoryController;
use App\Http\Controllers\Ai\AnalystPageController;
use App\Http\Controllers\Ai\AnalystStatusController;
use App\Http\Controllers\Ai\AnalystWidgetController;
use App\Http\Controllers\Ai\CustomerAnalystPanelController;
use Illuminate\Support\Facades\Route;

// P7-09a: «تحلیلگر هوشمند» — the AI analyst's page, its history and the progress of a manual run: read-only, behind ai.view (PRD §20: Viewer and
// Analyst have it). Cost/tokens/model are filtered inside the response by ai.view_cost, not by the route.
Route::middleware(['auth', 'permission:ai,view'])->prefix('ai/analyst')->name('ai.analyst.')->group(function () {
    Route::get('/', AnalystPageController::class)->name('index');
    Route::get('history', AnalystHistoryController::class)->name('history');
    Route::get('status', AnalystStatusController::class)->name('status');
    Route::get('widget', AnalystWidgetController::class)->name('widget');
});

// Manual generation needs ai.request (Analyst, Manager, Owner). It only QUEUES a job (a run is 20-45 s). The route throttle is a flood guard in its
// own bucket; the real limit (5 provider calls per user per hour) lives in AnalystService and is also checked before queueing.
Route::middleware(['auth', 'permission:ai,request', 'throttle:20,1,ai-generate'])->group(function () {
    Route::post('ai/analyst/generate', AnalystGenerateController::class)->name('ai.analyst.generate');
});

// P7-09b: the aggregate analyst panel of Customer 360 — behind customers.view like the page it sits on; segments/insight parts follow segments.view / ai.view inside.
Route::middleware(['auth', 'permission:customers,view'])->group(function () {
    Route::get('customers/{customer}/analyst-panel', CustomerAnalystPanelController::class)->whereNumber('customer')->name('customers.analyst-panel');
});
