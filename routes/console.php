<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// P2-10: the orders poll, every 15 minutes (PRD §22). The command queues one SyncEntityJob and exits, so there is no
// overlap protection here: SyncService refuses a start while a run is under an hour old, and the job is unique per entity.
Schedule::command('hm:sync', ['--entity' => 'orders'])->everyFifteenMinutes()->timezone('Asia/Tehran');

// P6 decision (ARCHITECTURE.md): a nightly catalog mirror, standalone for now — the full ordered chain
// PRD §22 describes (catalog before orders before RecomputeMetricsJob...) is P6-09's job, not yet built.
// CatalogSyncJob is unique per run, so no overlap protection is needed here either.
Schedule::command('hm:sync', ['--entity' => 'catalog'])->dailyAt('01:30')->timezone('Asia/Tehran');

// P2-11: GATE 1 evidence, refreshed every night: one ReconcileMonthJob per complete Jalali month. The command only queues.
// The flag is written in the command string: the array form ['--all' => true] compiles to `--all='1'`, which a flag that takes
// no value rejects — the nightly run would fail every night.
Schedule::command('hm:reconcile --all')->dailyAt('02:00')->timezone('Asia/Tehran');
