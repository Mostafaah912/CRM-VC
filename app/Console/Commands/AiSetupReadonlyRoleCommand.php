<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Services\AiReadonlyRoleService;
use Illuminate\Console\Command;

/**
 * `hm:ai-setup-readonly` (P7-01, GATE 4): creates/updates the `hm_ai_readonly` PostgreSQL role and
 * its grants from the admin ('pgsql') connection. Idempotent — safe to run again any time
 * AiReadonlyRoleService::ALLOWED_TABLES changes or DB_AI_READONLY_PASSWORD rotates in .env.
 * Deliberately a command, not a migration (CLAUDE.md §9/PRD §19 D7): a role is a cluster-level
 * object, not part of this app's schema, and its password must come from env, never a committed
 * migration file.
 */
final class AiSetupReadonlyRoleCommand extends Command
{
    protected $signature = 'hm:ai-setup-readonly {--statement-timeout-ms=5000 : statement_timeout (ms) enforced on the role for every session}';

    protected $description = 'Create or update the read-only hm_ai_readonly PostgreSQL role and its table grants (idempotent)';

    public function handle(AiReadonlyRoleService $service): int
    {
        $service->setup((int) $this->option('statement-timeout-ms'));

        $this->info('hm_ai_readonly is set up. SELECT-only on: '.implode(', ', AiReadonlyRoleService::ALLOWED_TABLES).'.');

        return self::SUCCESS;
    }
}
