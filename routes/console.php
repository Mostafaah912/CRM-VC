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
