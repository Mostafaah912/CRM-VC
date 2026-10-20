<?php

declare(strict_types=1);

namespace App\Modules\Ai\Anomaly;

use App\Modules\Ai\Dto\AnomalyEvent;
use App\Modules\Ai\Dto\AnomalyReport;
use Carbon\CarbonImmutable;

/**
 * Pure statistics over already-loaded daily rows: no database, no HTTP, no clock, no LLM — the same input always
 * yields the same report (tests assert it). Callers pass ONLY complete days.
 *
 * For each target day and metric: baseline = the same weekday over the previous `window_days` (>= `min_samples` of them),
 * robust z = (x - median) / max(1.4826 * MAD, min_sigma_fraction * |median|). It is an anomaly only if |z| >= z.info AND the
 * relative change >= min_relative_change AND the absolute change >= the metric's min_abs, and only when the day's baseline
 * order volume is at least min_baseline_orders (a 0-2 order store has no meaningful "normal"). Consecutive days with the
 * same metric and direction fold into one event.
 */
final class AnomalyEngine
{
    /** metric => unit, in output order. */
    public const METRICS = ['orders_count' => 'count', 'net_revenue' => 'toman', 'aov' => 'toman', 'customers_new' => 'count', 'refund_rate' => 'percent', 'repeat_share' => 'percent'];

    private const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    /**
     * @param  array<array-key, array{date: string, orders_count: int, net_revenue: int, refunds: int, revenue: int, customers_new: int, customers_repeat: int, customers_total: int}>  $rows
     */
    public function analyze(array $rows, CarbonImmutable $asOf, int $days, AnomalyConfig $config, ?int $dataAgeDays = null): AnomalyReport
    {
        $series = [];

        foreach ($rows as $row) {
            $series[$row['date']] = [
                'orders_count' => $row['orders_count'],
                'net_revenue' => $row['net_revenue'],
                'aov' => $row['orders_count'] > 0 ? $row['net_revenue'] / $row['orders_count'] : null,
                'customers_new' => $row['customers_new'],
                'refund_rate' => $row['revenue'] > 0 ? $row['refunds'] / $row['revenue'] : null,
                'repeat_share' => $row['customers_total'] > 0 ? $row['customers_repeat'] / $row['customers_total'] : null,
            ];
        }

        $weeks = intdiv($config->windowDays, 7);
        $evaluated = 0;
        $lowVolume = 0;
        $hits = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $asOf->subDays($offset)->toDateString();

            if (! isset($series[$date])) {
                continue;
            }

            $samples = [];

            foreach (array_keys(self::METRICS) as $metric) {
                $samples[$metric] = [];

                for ($k = 1; $k <= $weeks; $k++) {
                    $value = $series[$asOf->subDays($offset + 7 * $k)->toDateString()][$metric] ?? null;

                    if ($value !== null) {
                        $samples[$metric][] = $value;
                    }
                }
            }

            if (count($samples['orders_count']) < $config->minSamples) {
                continue;
            }

            if (RobustStats::median($samples['orders_count']) < $config->minBaselineOrders) {
                $lowVolume++;

                continue;
            }

            foreach (self::METRICS as $metric => $unit) {
                $x = $series[$date][$metric];

                if ($x === null || count($samples[$metric]) < $config->minSamples) {
                    continue;
                }

                $evaluated++;
                $median = RobustStats::median($samples[$metric]);

                if ($median <= 0) {
                    continue;
                }

                $sigma = max(1.4826 * RobustStats::mad($samples[$metric], $median), $config->minSigmaFraction * abs($median));
                $z = ($x - $median) / $sigma;
                $relative = ($x - $median) / abs($median);

                if (abs($z) < $config->z['info'] || abs($relative) < $config->minRelativeChange || abs($x - $median) < ($config->minAbs[$metric] ?? 0.0)) {
                    continue;
                }

                $hits[$metric][] = ['date' => $date, 'x' => $x, 'median' => $median, 'z' => $z, 'relative' => $relative];
            }
        }

        $events = $this->group($hits, $config);
        $status = match (true) {
            $evaluated > 0 => 'ok',
            $lowVolume > 0 => 'low_volume',
            $series === [] => 'no_data',
            default => 'insufficient_data',
        };

        return new AnomalyReport($status, $asOf->toDateString(), $dataAgeDays, $config->windowDays, $evaluated, $lowVolume, $events);
    }

    /**
     * @param  array<string, list<array{date: string, x: int|float, median: float, z: float, relative: float}>>  $hits
     * @return list<AnomalyEvent>
     */
    private function group(array $hits, AnomalyConfig $config): array
    {
        $events = [];

        foreach ($hits as $metric => $list) {
            $runs = [];

            foreach ($list as $hit) {
                $direction = $hit['z'] > 0 ? 'up' : 'down';
                $last = array_key_last($runs);

                if ($last !== null && $runs[$last]['direction'] === $direction && CarbonImmutable::parse(end($runs[$last]['hits'])['date'])->addDay()->toDateString() === $hit['date']) {
                    $runs[$last]['hits'][] = $hit;
                } else {
                    $runs[] = ['direction' => $direction, 'hits' => [$hit]];
                }
            }

            foreach ($runs as $run) {
                $peak = $run['hits'][0];

                foreach ($run['hits'] as $h) {
                    if (abs($h['z']) > abs($peak['z'])) {
                        $peak = $h;
                    }
                }

                $percent = self::METRICS[$metric] === 'percent';
                $abs = abs($peak['z']);
                $events[] = new AnomalyEvent(
                    $metric,
                    self::METRICS[$metric],
                    $run['hits'][0]['date'],
                    end($run['hits'])['date'],
                    count($run['hits']),
                    $peak['date'],
                    $percent ? round($peak['x'] * 100, 2) : (int) round($peak['x']),
                    $percent ? round($peak['median'] * 100, 2) : (int) round($peak['median']),
                    round($peak['relative'] * 100, 2),
                    round($peak['z'], 2),
                    $abs >= $config->z['critical'] ? 'critical' : ($abs >= $config->z['warning'] ? 'warning' : 'info'),
                    $run['direction'],
                    $this->knownEvent($peak['date'], $config),
                );
            }
        }

        $order = array_flip(array_keys(self::METRICS));
        usort($events, fn (AnomalyEvent $a, AnomalyEvent $b) => [self::SEVERITY_RANK[$b->severity], $b->to, $order[$a->metric], $a->direction]
            <=> [self::SEVERITY_RANK[$a->severity], $a->to, $order[$b->metric], $b->direction]);

        return $events;
    }

    private function knownEvent(string $date, AnomalyConfig $config): ?string
    {
        foreach ($config->knownEvents as $event) {
            if ($date >= $event['from'] && $date <= $event['to']) {
                return $event['name'];
            }
        }

        return null;
    }
}
