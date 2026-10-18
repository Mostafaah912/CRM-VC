<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Analyst\AnalystPeriods;
use App\Modules\Ai\Enums\AiInsightType;
use App\Modules\Ai\Services\AnalystService;
use App\Modules\Ai\Support\AiErrorExplainer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * `hm:ai-generate {daily_brief|weekly_review}` — a manual run of exactly what the scheduler runs, with a readable result. It holds no
 * logic: AnalystService decides everything (readiness, data age, cache, budget). Every error is mapped to one plain sentence that names
 * what to fix; none of them can contain a key, a prompt or model text.
 */
final class AiGenerateCommand extends Command
{
    protected $signature = 'hm:ai-generate {type=daily_brief : daily_brief or weekly_review} {--force : generate again even if an identical fresh insight exists} {--no-cache : same as --force} {--date= : the Tehran day this brief is about (YYYY-MM-DD); the data itself is always the newest available}';

    protected $description = 'Generate (or reuse) an AI insight now, the same way the scheduled job does';

    public function handle(AnalystService $analyst, AnalystPeriods $periods): int
    {
        $type = AiInsightType::tryFrom((string) $this->argument('type'));

        if ($type === null || ! in_array($type, [AiInsightType::DailyBrief, AiInsightType::WeeklyReview], true)) {
            $this->error('type must be daily_brief or weekly_review.');

            return self::FAILURE;
        }

        $period = $periods->for($type);

        if (($date = $this->option('date')) !== null) {
            $day = $this->parseDate((string) $date);

            if ($day === null) {
                $this->error('--date must be a past Tehran day, YYYY-MM-DD.');

                return self::FAILURE;
            }

            $period = $type === AiInsightType::DailyBrief ? $periods->day($day) : $periods->weekly($day->addDays(7));
        }

        try {
            $result = $analyst->generate($type, $period, null, (bool) $this->option('force') || (bool) $this->option('no-cache'));
        } catch (Throwable $e) {
            $this->error(AiErrorExplainer::explain($e));

            return self::FAILURE;
        }

        $insight = $result->insight;
        $payload = $insight->payload;

        $this->line(sprintf('%s | insight #%d | %s (%s .. %s)', $result->fromCache ? 'CACHE HIT (nothing called, nothing spent)' : 'GENERATED', $insight->id, $insight->scope, $period->jalaliStart, $period->jalaliEnd));
        $this->line('headline: '.($payload['headline'] ?? ''));
        $this->line(sprintf('findings: %d insights, %d risks, %d opportunities, %d recommendations', count($payload['insights'] ?? []), count($payload['risks'] ?? []), count($payload['opportunities'] ?? []), count($payload['recommendations'] ?? [])));
        $this->line(sprintf('model: %s/%s | prompt: %s | tokens in/out: %d/%d | cost: %s USD | %d ms | tool calls stored: %d', $insight->provider, $insight->model, $insight->prompt_version, $insight->input_tokens, $insight->output_tokens, $insight->cost_usd, (int) $insight->duration_ms, $result->toolCalls));

        $reasoning = $result->generated === null ? 0 : array_sum(array_map(fn ($r) => $r->reasoningTokens, $result->generated->responses));
        $this->line(sprintf('confidence: %s | reasoning tokens (inside out): %d | adjustments: %s', $payload['confidence'] ?? '-', $reasoning, json_encode($payload['meta']['adjustments'] ?? [], JSON_UNESCAPED_UNICODE)));

        if ($result->generated !== null && $result->generated->redactions > 0) {
            $this->warn("{$result->generated->redactions} piece(s) of text in the answer were redacted.");
        }

        if (($payload['data_quality_note'] ?? '') !== '') {
            $this->warn('data quality: '.$payload['data_quality_note']);
        }

        return self::SUCCESS;
    }

    private function parseDate(string $date): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d\d-\d\d\z/', $date) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Tehran');
        } catch (Throwable) {
            return null;
        }

        return $day === null || $day->toDateString() !== $date || $day->isFuture() ? null : $day;
    }
}
