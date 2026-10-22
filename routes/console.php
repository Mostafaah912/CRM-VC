<?php

use App\Jobs\HealthCheckJob;
use App\Modules\Ai\Jobs\GenerateDailyBriefJob;
use App\Modules\Ai\Jobs\GenerateWeeklyReviewJob;
use App\Modules\Analytics\Jobs\BuildAffinityJob;
use App\Modules\Sync\Jobs\PruneLogsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// P2-10: the orders poll, every 15 minutes (PRD §22). The command queues one SyncEntityJob and exits, so there is no
// overlap protection here: SyncService refuses a start while a run is under an hour old, and the job is unique per entity.
Schedule::command('hm:sync', ['--entity' => 'orders'])->everyFifteenMinutes()->timezone('Asia/Tehran');

// P6-10, PRD §22: "every 15m: ..., HealthCheckJob" — pairs with the orders poll above.
Schedule::job(new HealthCheckJob)->everyFifteenMinutes()->timezone('Asia/Tehran');

// P6-09: the full ordered nightly chain PRD §22 describes — catalog+orders sync, RecomputeMetricsJob('full'),
// every P6 Analytics rebuild, segments, then a recent-months reconciliation — replaces the standalone catalog
// (01:30) and `hm:reconcile --all` (02:00) entries this schedule used to carry as stand-ins (ARCHITECTURE.md).
// No overlap protection needed here: the command only queues a Bus::chain, and every step inside it is already
// ShouldBeUnique on its own. `hm:reconcile --all` still exists, unscheduled, for a manual full-history re-check.
Schedule::command('hm:nightly-chain')->dailyAt('03:00')->timezone('Asia/Tehran');

// P6-10, PRD §22: "daily 04:30 PruneLogsJob" — 30 minutes after the nightly chain starts, giving it room
// to finish (the chain's own timeout deadline is 05:00, checked by HealthCheckJob's NightlyChainTimeout alert).
Schedule::job(new PruneLogsJob)->dailyAt('04:30')->timezone('Asia/Tehran');

// PRD §22: "weekly Sat 04:00 BuildAffinityJob" — a full 4-level rebuild, standalone (not part of the nightly
// chain: it does not depend on, and nothing nightly depends on, product_affinities).
Schedule::job(new BuildAffinityJob)->weeklyOn(6, '04:00')->timezone('Asia/Tehran');

// P7-08, PRD §22: "daily 07:00 GenerateDailyBriefJob" and "weekly Sat 07:30 GenerateWeeklyReviewJob" (queue `ai`, Asia/Tehran). Their
// own entries, deliberately NOT part of hm:nightly-chain: a Bus::chain stops at its first failure and an AI failure must never stop the
// data chain. Times and the weekday come from config (ai.generation.*). Each job first checks that today's metrics run finished, and is a
// silent no-op while AI_ENABLED is false.
Schedule::job(new GenerateDailyBriefJob)->dailyAt((string) config('ai.generation.daily_at'))->timezone('Asia/Tehran');
Schedule::job(new GenerateWeeklyReviewJob)->weeklyOn((int) config('ai.generation.weekly_day'), (string) config('ai.generation.weekly_at'))->timezone('Asia/Tehran');

// P8-02, PRD §21/§22: the encrypted off-server backup, 02:00 Tehran — an hour before the 03:00 nightly chain. pg_dump reads one
// MVCC snapshot and takes no write-blocking locks, so a slow dump cannot corrupt or stall the chain; `withoutOverlapping` stops a second
// dump from starting on top of a long one, `onOneServer` keeps a multi-server setup to one. Off until BACKUP_ENABLED=true.
Schedule::command('backup:run')->dailyAt('02:00')->timezone('Asia/Tehran')
    ->withoutOverlapping(120)->onOneServer()
    ->when(fn (): bool => (bool) config('backup.enabled'));
