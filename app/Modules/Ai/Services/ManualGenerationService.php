<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\User;
use App\Modules\Ai\Analyst\AnalystPeriods;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Exceptions\AnalystNotReadyException;
use App\Modules\Ai\Exceptions\ManualRequestThrottledException;
use App\Modules\Ai\Jobs\GenerateManualInsightJob;
use App\Modules\Ai\Support\AnalystMessages;
use App\Modules\Core\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * «تولید دستی» (P7-09a). A real generation takes 20-45 s, so the click never calls the provider (CLAUDE.md §1: over ~2 s is a queued Job):
 * request() refuses the cheap, certain cases at once (AI off / not configured, throttled, already running), otherwise queues
 * GenerateManualInsightJob and returns; the screen polls state(). The job calls run(), which is AnalystService::generate() with
 * requested_by = the user, and records only a short result in the cache — never an exception message, a key or model text.
 *
 * State lives in the cache (15 min), not in a table: it is a progress indicator, the durable record is ai_insights itself.
 */
final class ManualGenerationService
{
    private const TTL_MINUTES = 15;

    public function __construct(
        private readonly AnalystReadiness $readiness,
        private readonly AnalystService $analyst,
        private readonly AnalystPeriods $periods,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array<string, mixed> the state the screen should show now
     *
     * @throws AnalystNotReadyException
     * @throws ManualRequestThrottledException
     */
    public function request(AiInsightType $type, User $user, bool $force): array
    {
        $problem = $this->readiness->generationProblem();

        if ($problem !== null) {
            throw new AnalystNotReadyException($problem, $this->readiness->describe($problem));
        }

        $this->analyst->assertManualAllowed($user->id);

        // One run per type at a time: add() is atomic, the loser is told it is already running.
        if (! Cache::add($this->key($type), ['status' => 'queued', 'by' => $user->id, 'force' => $force], now()->addMinutes(self::TTL_MINUTES))) {
            return $this->state($type) + ['already_running' => true];
        }

        $this->audit->recordUser($user, 'ai.generate.requested', 'ai_insight_request', 0, null, ['type' => $type->value, 'force' => $force], 'ai');
        GenerateManualInsightJob::dispatch($type, $user->id, $force);

        return $this->state($type);
    }

    /** Called by the job. Never throws: the outcome is written to the state. */
    public function run(AiInsightType $type, int $userId, bool $force): void
    {
        $this->put($type, ['status' => 'running', 'by' => $userId]);

        try {
            $result = $this->analyst->generate($type, $this->periods->for($type), $userId, $force);
            $this->put($type, ['status' => 'done', 'by' => $userId, 'insight_id' => $result->insight->id, 'from_cache' => $result->fromCache]);
        } catch (Throwable $e) {
            $m = AnalystMessages::forException($e);
            $this->put($type, ['status' => 'failed', 'by' => $userId, 'code' => $m['code'], 'message' => $m['message']]);
        }
    }

    /** The worker died or timed out before run() could write an outcome. */
    public function markCrashed(AiInsightType $type, int $userId): void
    {
        $this->put($type, ['status' => 'failed', 'by' => $userId, 'code' => 'unexpected', 'message' => 'اجرای تولید تحلیل ناگهان متوقف شد؛ بعداً دوباره تلاش کنید.']);
    }

    /** @return array<string, mixed> */
    public function state(AiInsightType $type): array
    {
        $s = Cache::get($this->key($type));

        return is_array($s) ? $this->public($s) : ['status' => 'idle'];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function public(array $s): array
    {
        return array_filter([
            'status' => (string) ($s['status'] ?? 'idle'),
            'insight_id' => $s['insight_id'] ?? null,
            'from_cache' => $s['from_cache'] ?? null,
            'code' => $s['code'] ?? null,
            'message' => $s['message'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $state */
    private function put(AiInsightType $type, array $state): void
    {
        Cache::put($this->key($type), $state, now()->addMinutes(self::TTL_MINUTES));
    }

    private function key(AiInsightType $type): string
    {
        return 'ai:manual:state:'.$type->value;
    }
}
