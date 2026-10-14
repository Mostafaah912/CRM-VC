<?php

declare(strict_types=1);

namespace App\Modules\Ai\Anomaly;

/** config('ai.anomaly') read once, with safe floors so a bad value cannot disable a guard. */
final readonly class AnomalyConfig
{
    /**
     * @param  array{info: float, warning: float, critical: float}  $z
     * @param  array<string, float>  $minAbs
     * @param  list<array{name: string, from: string, to: string}>  $knownEvents
     */
    public function __construct(
        public int $windowDays,
        public int $minSamples,
        public int $minBaselineOrders,
        public float $minRelativeChange,
        public float $minSigmaFraction,
        public array $z,
        public array $minAbs,
        public array $knownEvents,
        public int $maxLookbackDays,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('ai.anomaly');
        $z = (array) ($c['z'] ?? []);
        $info = max(2.0, (float) ($z['info'] ?? 3.5));
        $warning = max($info, (float) ($z['warning'] ?? 5.0));
        $events = [];

        foreach ((array) ($c['known_events'] ?? []) as $e) {
            if (is_array($e) && isset($e['name'], $e['from'], $e['to'])) {
                $events[] = ['name' => (string) $e['name'], 'from' => (string) $e['from'], 'to' => (string) $e['to']];
            }
        }

        return new self(
            max(14, (int) ($c['window_days'] ?? 56)),
            max(3, (int) ($c['min_samples'] ?? 4)),
            max(1, (int) ($c['min_baseline_orders'] ?? 5)),
            max(0.05, (float) ($c['min_relative_change'] ?? 0.30)),
            max(0.01, (float) ($c['min_sigma_fraction'] ?? 0.10)),
            ['info' => $info, 'warning' => $warning, 'critical' => max($warning, (float) ($z['critical'] ?? 8.0))],
            array_map(fn ($m) => (float) ($m['min_abs'] ?? 0), (array) ($c['metrics'] ?? [])),
            $events,
            max(1, (int) ($c['max_lookback_days'] ?? 1100)),
        );
    }
}
