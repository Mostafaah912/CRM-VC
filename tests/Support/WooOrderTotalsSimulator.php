<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Sync\Exceptions\WooFixtureNotFoundException;
use App\Modules\Sync\Services\WooClient;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooPage;
use Carbon\CarbonImmutable;
use Generator;
use RuntimeException;
use Throwable;

/**
 * A stand-in for WooCommerce's orders list for reconciliation tests. It filters by CREATED date with `after`/`before`
 * (both EXCLUSIVE, read as UTC only when dates_are_gmt=true is sent — otherwise it refuses, so a missing flag fails a
 * test), honours `_fields`, paginates, and reports X-WP-Total like Woo. It serves `orders` only — asking it for a reports
 * endpoint is an error — and records every query so tests can assert exactly what was asked. No network.
 *
 * Each order spec: created (UTC 'Y-m-d H:i:s'), status, total (int Toman), optional currency, id, totalRaw (a raw JSON value
 * for a malformed total).
 */
final class WooOrderTotalsSimulator implements WooClient
{
    /** @var list<array<array-key, mixed>> */
    private array $orders = [];

    /** @var list<array{endpoint: string, page: int, query: array<string, scalar>}> */
    public array $requests = [];

    /**
     * @param  list<array{created: string, status: string, total: int, currency?: string, id?: int, totalRaw?: mixed}>  $orders
     */
    public function __construct(
        array $orders,
        private readonly int $perPage = 50,
        private readonly ?int $failOnPage = null,
        private readonly ?Throwable $failure = null,
        private readonly ?int $reportedTotal = null,
        private readonly bool $omitTotal = false,
    ) {
        foreach (array_values($orders) as $i => $order) {
            $this->orders[] = [
                'id' => $order['id'] ?? 8000 + $i,
                'status' => $order['status'],
                'currency' => $order['currency'] ?? 'IRT',
                'total' => $order['totalRaw'] ?? (string) $order['total'],
                'date_created_gmt' => CarbonImmutable::parse($order['created'], 'UTC')->format('Y-m-d\TH:i:s'),
                'billing' => ['phone' => '09121234567'], // present in the "full" payload; only requested fields may come back
            ];
        }
    }

    public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
    {
        if ($endpoint !== 'orders') {
            throw new WooFixtureNotFoundException("The totals simulator serves only orders, not {$endpoint}.");
        }

        $this->requests[] = ['endpoint' => $endpoint, 'page' => $page, 'query' => $query];

        if ($page === ($this->failOnPage ?? ($this->failure !== null ? 1 : null))) {
            throw $this->failure ?? new RuntimeException('simulated Woo failure');
        }

        if (($query['dates_are_gmt'] ?? null) !== 'true') {
            throw new WooFixtureNotFoundException('The totals simulator needs dates_are_gmt=true.');
        }

        $after = isset($query['after']) ? CarbonImmutable::parse((string) $query['after'], 'UTC') : null;
        $before = isset($query['before']) ? CarbonImmutable::parse((string) $query['before'], 'UTC') : null;
        $fields = isset($query['_fields']) ? explode(',', (string) $query['_fields']) : null;

        $matching = array_values(array_filter($this->orders, function (array $o) use ($after, $before): bool {
            $created = CarbonImmutable::parse($o['date_created_gmt'], 'UTC');

            return ($after === null || $created->greaterThan($after)) && ($before === null || $created->lessThan($before));
        }));

        $items = array_map(
            fn (array $o): array => $fields === null ? $o : array_intersect_key($o, array_flip($fields)),
            array_slice($matching, ($page - 1) * $this->perPage, $this->perPage),
        );

        return new WooPage(
            $items,
            $page,
            intdiv(count($matching) + $this->perPage - 1, $this->perPage),
            $this->omitTotal ? null : ($this->reportedTotal ?? count($matching)),
        );
    }

    public function pages(string $endpoint, ?SyncWindow $window = null, array $query = []): Generator
    {
        $page = 1;

        do {
            $result = $this->page($endpoint, $page, $window, $query);

            yield $result;

            $page++;
        } while ($result->hasMore());
    }
}
