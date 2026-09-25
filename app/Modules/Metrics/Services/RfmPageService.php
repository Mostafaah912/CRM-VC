<?php

declare(strict_types=1);

namespace App\Modules\Metrics\Services;

use App\Modules\Metrics\Enums\RfmSegment;
use App\Support\TehranDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRD §12's RFM page (P4-08 part B): segment distribution, the R/F/M score histogram, the latest
 * run's status, and the top 10 champions — everything read straight from customer_metrics /
 * metric_runs, both owned by this module, so nothing here needs another module's table or model.
 *
 * The top-champions list carries customer_id, total_revenue and rfm_score ONLY — never a name or
 * phone (CLAUDE.md §6/§7's "no PII leaves an aggregate view" spirit applies here too, even though this
 * isn't the AI gateway): the page links to the customer by id and lets Customer 360 show the rest to
 * a viewer who already holds customers.view.
 */
final class RfmPageService
{
    private const TOP_CHAMPIONS = 10;

    /**
     * @return array{
     *     segments: array<string, int>,
     *     scores: array{r: array<int, int>, f: array<int, int>, m: array<int, int>},
     *     latest_run: array{computed_at: string|null, computed_at_iso: string|null, status: string|null}|null,
     *     top_champions: list<array{customer_id: int, total_revenue: int, rfm_score: string|null}>,
     * }
     */
    public function getData(): array
    {
        return [
            'segments' => $this->segmentDistribution(),
            'scores' => [
                'r' => $this->scoreDistribution('r_score'),
                'f' => $this->scoreDistribution('f_score'),
                'm' => $this->scoreDistribution('m_score'),
            ],
            'latest_run' => $this->latestRun(),
            'top_champions' => $this->topChampions(),
        ];
    }

    /** @return array<string, int> every PRD §12 segment, plus 'none' for a not-yet-eligible customer — never a missing key */
    private function segmentDistribution(): array
    {
        $counts = DB::table('customer_metrics')
            ->selectRaw('rfm_segment, count(*) as n')
            ->groupBy('rfm_segment')
            ->pluck('n', 'rfm_segment');

        $segments = [];

        foreach (RfmSegment::cases() as $segment) {
            $segments[$segment->value] = (int) ($counts[$segment->value] ?? 0);
        }

        // A NULL rfm_segment groups under the PHP array key '' (pluck coerces a null key that way).
        $segments['none'] = (int) ($counts[''] ?? 0);

        return $segments;
    }

    /**
     * @param  'r_score'|'f_score'|'m_score'  $column
     * @return array<int, int> scores 1..5, each defaulting to 0 — PHP always coerces a numeric-string
     *                         key back to int, but the JSON this becomes still serializes as an object ("1".."5")
     *                         since these keys are not a zero-based sequential list.
     */
    private function scoreDistribution(string $column): array
    {
        // A fixed literal per column, never interpolated: PHPStan (and Rule 7) require selectRaw's
        // argument be a literal string, not a variable-built one, even for this hardcoded-input case.
        $selectRaw = match ($column) {
            'r_score' => 'r_score, count(*) as n',
            'f_score' => 'f_score, count(*) as n',
            'm_score' => 'm_score, count(*) as n',
        };

        $counts = DB::table('customer_metrics')
            ->whereNotNull($column)
            ->selectRaw($selectRaw)
            ->groupBy($column)
            ->pluck('n', $column);

        $distribution = [];

        foreach (range(1, 5) as $score) {
            $distribution[$score] = (int) ($counts[$score] ?? 0);
        }

        return $distribution;
    }

    /** @return array{computed_at: string|null, computed_at_iso: string|null, status: string|null}|null */
    private function latestRun(): ?array
    {
        $run = DB::table('metric_runs')->orderByDesc('id')->first(['finished_at', 'status']);

        if ($run === null) {
            return null;
        }

        $finishedAt = $run->finished_at === null ? null : CarbonImmutable::parse((string) $run->finished_at, 'UTC');

        return [
            'computed_at' => $finishedAt === null ? null : TehranDateTime::format($finishedAt),
            'computed_at_iso' => $finishedAt === null ? null : $finishedAt->toIso8601ZuluString(),
            'status' => (string) $run->status,
        ];
    }

    /** @return list<array{customer_id: int, total_revenue: int, rfm_score: string|null}> */
    private function topChampions(): array
    {
        $rows = DB::table('customer_metrics')
            ->where('rfm_segment', RfmSegment::Champion->value)
            ->orderByDesc('total_revenue')
            ->limit(self::TOP_CHAMPIONS)
            ->get(['customer_id', 'total_revenue', 'rfm_score'])
            ->map(fn (object $row): array => [
                'customer_id' => (int) $row->customer_id,
                'total_revenue' => (int) $row->total_revenue,
                'rfm_score' => $row->rfm_score === null ? null : (string) $row->rfm_score,
            ])
            ->all();

        return array_values($rows);
    }
}
