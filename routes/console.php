<?php

use App\Modules\Analytics\Jobs\BuildAffinityJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// P2-10: the orders poll, every 15 minutes (PRD §22). The command queues one SyncEntityJob and exits, so there is no
// overlap protection here: SyncService refuses a start while a run is under an hour old, and the job is unique per entity.
Schedule::command('hm:sync', ['--entity' => 'orders'])->everyFifteenMinutes()->timezone('Asia/Tehran');

// P6-09: the full ordered nightly chain PRD §22 describes — catalog+orders sync, RecomputeMetricsJob('full'),
// every P6 Analytics rebuild, segments, then a recent-months reconciliation — replaces the standalone catalog
// (01:30) and `hm:reconcile --all` (02:00) entries this schedule used to carry as stand-ins (ARCHITECTURE.md).
// No overlap protection needed here: the command only queues a Bus::chain, and every step inside it is already
// ShouldBeUnique on its own. `hm:reconcile --all` still exists, unscheduled, for a manual full-history re-check.
Schedule::command('hm:nightly-chain')->dailyAt('03:00')->timezone('Asia/Tehran');

// PRD §22: "weekly Sat 04:00 BuildAffinityJob" — a full 4-level rebuild, standalone (not part of the nightly
// chain: it does not depend on, and nothing nightly depends on, product_affinities).
Schedule::job(new BuildAffinityJob)->weeklyOn(6, '04:00')->timezone('Asia/Tehran');
