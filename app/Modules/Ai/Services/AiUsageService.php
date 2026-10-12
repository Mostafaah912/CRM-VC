<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Models\AiUsageDaily;
use App\Modules\Ai\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ai_usage_daily ledger (PRD §09) and the month-to-date totals every budget decision reads. The month is the
 * Gregorian calendar month in Asia/Tehran and each row's `date` is the Tehran calendar day, so a month boundary
 * resets usage by construction — there is nothing to clear.
 *
 * Why this table is INCREMENTED despite CLAUDE.md §2 ("never increment a stored aggregate"): that rule is for
 * derived tables rebuildable from source rows. A provider call that fails schema validation, or a retry, produces no
 * ai_insights row, so this ledger is the only record of that spend and cannot be recomputed. It is written in one
 * transaction under a row lock, with no raw SQL, so concurrent writers cannot lose an update.
 */
final class AiUsageService
{
    private const TZ = 'Asia/Tehran';

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ);
    }

    public function monthKey(?CarbonImmutable $at = null): string
    {
        return ($at ?? $this->today())->setTimezone(self::TZ)->format('Y-m');
    }

    public function record(AiResponse $response): void
    {
        $date = $this->today()->toDateString();

        DB::transaction(function () use ($response, $date): void {
            AiUsageDaily::query()->insertOrIgnore([
                'date' => $date, 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => '0',
            ]);

            $row = AiUsageDaily::query()->whereKey($date)->lockForUpdate()->firstOrFail();

            $row->forceFill([
                'requests' => $row->requests + 1,
                'input_tokens' => $row->input_tokens + $response->inputTokens,
                'output_tokens' => $row->output_tokens + $response->outputTokens,
                'cost_usd' => bcadd(Decimal::of($row->cost_usd), Decimal::of($response->costUsd), 5),
            ])->save();
        });
    }

    /** @return array{usd: numeric-string, tokens: int, requests: int, last_date: string|null} */
    public function monthTotals(?CarbonImmutable $at = null): array
    {
        $start = ($at ?? $this->today())->setTimezone(self::TZ)->startOfMonth();
        $rows = AiUsageDaily::query()
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->orderBy('date')
            ->get();

        $usd = '0';
        $tokens = 0;
        $requests = 0;

        foreach ($rows as $row) {
            $usd = bcadd($usd, Decimal::of($row->cost_usd), 5);
            $tokens += $row->input_tokens + $row->output_tokens;
            $requests += $row->requests;
        }

        $last = $rows->where('requests', '>', 0)->last();

        return ['usd' => $usd, 'tokens' => $tokens, 'requests' => $requests, 'last_date' => $last === null ? null : (string) $last->date];
    }
}
