<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;

/**
 * Deterministic synthetic daily_metrics rows with a fixed weekly shape (Iran: Thu/Fri are the weak days) and a tiny,
 * repeating noise, so a real MAD exists and every expected z is reproducible. No randomness anywhere.
 */
final class AnomalySeries
{
    /** orders by Carbon dayOfWeek (0 = Sunday ... 6 = Saturday): Sat/Sun/Mon/Tue/Wed busy, Thu half, Fri quiet. */
    private const BASE = [6 => 40, 0 => 38, 1 => 42, 2 => 40, 3 => 44, 4 => 22, 5 => 14];

    private const NOISE = [0, 1, -1, 0, 2, -2, 1, -1];

    /**
     * @param  array<string, array<string, int|float>>  $overrides  date => column overrides, e.g. ['2026-10-07' => ['orders_count' => 120]]
     * @return list<array{date: string, orders_count: int, net_revenue: int, refunds: int, revenue: int, customers_new: int, customers_repeat: int, customers_total: int}>
     */
    public static function make(string $from, string $to, array $overrides = []): array
    {
        $rows = [];
        $i = 0;

        for ($d = CarbonImmutable::parse($from); $d <= CarbonImmutable::parse($to); $d = $d->addDay(), $i++) {
            $date = $d->toDateString();
            $orders = self::BASE[$d->dayOfWeek] + self::NOISE[intdiv($i, 7) % 8 === 0 ? $i % 8 : ($i + intdiv($i, 7)) % 8];
            $aov = 200_000 + (self::NOISE[$i % 8] * 1_000);
            $net = $orders * $aov;
            $revenue = (int) round($net / 0.97);
            $new = (int) round($orders * 0.4);
            $row = [
                'date' => $date, 'orders_count' => $orders, 'net_revenue' => $net, 'refunds' => $revenue - $net, 'revenue' => $revenue,
                'customers_new' => $new, 'customers_repeat' => $orders - $new, 'customers_total' => $orders,
            ];
            $rows[] = array_merge($row, $overrides[$date] ?? []);
        }

        return $rows;
    }
}
