<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\HealthCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queues HealthCheckService::check() (PRD §22: "every 15m: ..., HealthCheckJob") — a thin wrapper, no
 * business logic here (CLAUDE.md §1/§5: "a Job resolves a Service and calls it"). Not under app/Modules:
 * HealthCheckService itself reads across Sync + Metrics + Core, which no single module's dependency
 * table allows — see HealthCheckService's own docblock (P6-10, ARCHITECTURE.md).
 *
 * Queue = `default` (PRD §22 names critical/sync/metrics/ai/default; this is a light, frequent read, not
 * heavy analytics work). `tries = 1`, `ShouldBeUnique` + `uniqueFor > timeout`: a second check racing the
 * first would just repeat the same reads — harmless but wasteful, and the 15-minute schedule already
 * limits how often it runs.
 */
final class HealthCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'health-check';
    }

    public function handle(HealthCheckService $health): void
    {
        $raised = $health->check();

        if ($raised !== []) {
            Log::warning('HealthCheckJob raised alerts', ['kinds' => $raised]);
        }
    }
}
