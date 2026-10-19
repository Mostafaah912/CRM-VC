<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Context\DataFreshnessReader;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Models\AiInsight;
use App\Modules\Ai\Support\AnalystMessages;
use App\Modules\Core\Services\AuditService;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Everything the «تحلیلگر هوشمند» screen reads (P7-09a). Read-only: it never generates, never calls a provider, never writes.
 * Sources: ai_insights (latest per type + paged history), AnalystReadiness (is AI on?), BudgetStatusService (admin only),
 * DataFreshnessReader (is the data stale right now?) and the audit log's alerts for recent failures/refusals — no new table.
 */
final class AnalystPageService
{
    private const TYPES = [AiInsightType::DailyBrief, AiInsightType::WeeklyReview];

    // Core\Enums\AlertKind values (a Core enum cannot be named from here; the audit log stores these strings).
    private const ALERT_GENERATION_FAILURE = 'ai_generation_failure';

    private const ALERT_BUDGET = 'ai_budget_threshold';

    public function __construct(
        private readonly AnalystReadiness $readiness,
        private readonly BudgetStatusService $budget,
        private readonly DataFreshnessReader $freshness,
        private readonly AnalystInsightPresenter $presenter,
        private readonly ManualGenerationService $manual,
        private readonly AnalystService $analyst,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(?int $userId, bool $withCost): array
    {
        $problem = $this->readiness->generationProblem();
        $fresh = $this->safeFreshness();
        $asOf = $fresh['as_of'];
        $latest = [];
        $generation = [];

        foreach (self::TYPES as $type) {
            $row = AiInsight::query()->where('type', $type->value)->orderByDesc('id')->first();
            $latest[$type->value] = $row === null ? null : $this->presenter->present($row, $withCost, $asOf);
            $generation[$type->value] = $this->manual->state($type);
        }

        $last = AiInsight::query()->whereIn('type', array_map(fn (AiInsightType $t) => $t->value, self::TYPES))->orderByDesc('id')->first();

        $data = [
            'enabled' => config('ai.enabled') === true,
            'readiness' => ['ready' => $problem === null, 'code' => $problem, 'message' => $problem === null ? null : AnalystMessages::forReadiness($problem)],
            'latest' => $latest,
            'generation' => $generation,
            'freshness' => ['stale' => $fresh['stale'], 'data_age_days' => $fresh['age'], 'as_of' => $asOf === null ? null : $fresh['as_of_jalali']],
            'definitions' => ['realized_statuses' => $this->realizedStatuses()],
            'last_run' => $last === null ? null : ['type' => $last->type, 'at' => TehranDateTime::format($last->created_at), 'manual' => $last->requested_by !== null],
            'recent_problem' => $this->recentProblem(),
            'manual' => [
                'max_per_window' => max(1, (int) config('ai.manual.max_requests', 5)),
                'window_minutes' => max(1, (int) config('ai.manual.per_minutes', 60)),
                'remaining' => $userId === null ? 0 : $this->analyst->manualAttemptsRemaining($userId),
            ],
        ];

        if ($withCost) {
            $b = $this->budget->status();
            $data['budget'] = [
                'month' => $b->month, 'used_usd' => $b->usedUsd, 'cap_usd' => $b->capUsd, 'percent_usd' => $b->percentUsd,
                'used_tokens' => $b->usedTokens, 'cap_tokens' => $b->capTokens, 'percent_tokens' => $b->percentTokens,
                'requests' => $b->requests, 'state' => $b->state->value, 'last_usage_date' => $b->lastUsageDate,
            ];
        }

        return $data;
    }

    /**
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int, last_page: int}
     */
    public function history(AiInsightType $type, int $page, int $perPage, bool $withCost): array
    {
        $asOf = $this->safeFreshness()['as_of'];
        $paginator = AiInsight::query()->where('type', $type->value)->orderByDesc('id')->paginate(max(1, min(50, $perPage)), ['*'], 'page', max(1, $page));

        return [
            'data' => array_values(array_map(fn (AiInsight $r) => $this->presenter->present($r, $withCost, $asOf), $paginator->items())),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * The statuses that make an order «تحقق‌یافته» (config, with Persian labels): the help text beside «درآمد» is built from these, never typed in the UI.
     *
     * @return list<array{slug: string, label: string}>
     */
    private function realizedStatuses(): array
    {
        $labels = (array) config('woo.status_labels', []);
        $out = [];

        foreach ((array) config('woo.realized_statuses', []) as $slug) {
            $out[] = ['slug' => (string) $slug, 'label' => (string) ($labels[$slug] ?? $slug)];
        }

        return $out;
    }

    /** @return array{type: string, reason: string, message: string, at: string}|null */
    private function recentProblem(): ?array
    {
        $alerts = $this->audit->recentAlerts([self::ALERT_GENERATION_FAILURE, self::ALERT_BUDGET], CarbonImmutable::now()->subDays(3), 1);
        $a = $alerts[0] ?? null;

        if ($a === null) {
            return null;
        }

        $reason = (string) ($a['context']['reason'] ?? '');
        $message = $a['kind'] === self::ALERT_BUDGET ? 'بودجه‌ی هوش مصنوعی به آستانه‌ی هشدار رسیده یا تمام شده است.' : AnalystMessages::forAlertReason($reason);

        return ['type' => (string) ($a['context']['type'] ?? ''), 'reason' => $a['kind'] === self::ALERT_BUDGET ? 'budget' : $reason, 'message' => $message, 'at' => TehranDateTime::format($a['at'])];
    }

    /** @return array{stale: bool, age: int|null, as_of: string|null, as_of_jalali: string|null} */
    private function safeFreshness(): array
    {
        try {
            $f = $this->freshness->read();
        } catch (Throwable) {
            // The read-only role/connection may be unavailable (e.g. AI never set up): the page must still render.
            return ['stale' => false, 'age' => null, 'as_of' => null, 'as_of_jalali' => null];
        }

        return [
            'stale' => $f['stale_data'] === true,
            'age' => is_int($f['data_age_days']) ? $f['data_age_days'] : null,
            'as_of' => $f['as_of']['iso'] ?? null,
            'as_of_jalali' => $f['as_of']['jalali'] ?? null,
        ];
    }
}
