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

/**
 * A small stand-in for WooCommerce's orders list, for tests that need the WINDOW to matter (recorded fixtures ignore it).
 * It honours modified_after / modified_before — both EXCLUSIVE, the harshest reading, so it is the overlap that keeps a
 * boundary tie from being lost — orderby=modified asc, and pagination with X-WP-TotalPages semantics. Orders are copies
 * of a recorded order with their own id and modified time. It serves `orders` only (no refunds), makes no HTTP call.
 */
final class WooOrdersSimulator implements WooClient
{
    /** @var list<array{modified: CarbonImmutable, payload: array<array-key, mixed>}> */
    private array $orders = [];

    /** @var list<array{page: int, window: ?SyncWindow}> */
    public array $requests = [];

    /**
     * @param  list<string>  $modifiedUtc  one order per UTC timestamp
     */
    public function __construct(array $modifiedUtc, private readonly int $perPage = 2, private readonly ?int $failOnPage = null)
    {
        $template = WooPayloads::items('orders')[0];

        foreach (array_values($modifiedUtc) as $i => $when) {
            $at = CarbonImmutable::parse($when, 'UTC');
            $payload = WooPayloads::set($template, 'id', 9000 + $i);
            $payload = WooPayloads::set($payload, 'number', (string) (9000 + $i));
            $payload = WooPayloads::set($payload, 'date_created_gmt', $at->format('Y-m-d\TH:i:s'));
            $payload = WooPayloads::set($payload, 'date_modified_gmt', $at->format('Y-m-d\TH:i:s'));
            $payload = WooPayloads::set($payload, 'refunds', []);

            $this->orders[] = ['modified' => $at, 'payload' => $payload];
        }

        usort($this->orders, fn (array $a, array $b): int => [$a['modified']->getTimestamp(), $a['payload']['id']] <=> [$b['modified']->getTimestamp(), $b['payload']['id']]);
    }

    /** $count orders, $stepMinutes apart, from $first — comfortably wider than the 10-minute overlap. */
    public static function every(int $count, string $first = '2026-06-01 06:00:00', int $stepMinutes = 20, int $perPage = 2, ?int $failOnPage = null): self
    {
        $start = CarbonImmutable::parse($first, 'UTC');

        return new self(
            array_map(fn (int $i): string => $start->addMinutes($i * $stepMinutes)->format('Y-m-d H:i:s'), range(0, $count - 1)),
            $perPage,
            $failOnPage,
        );
    }

    public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
    {
        if ($endpoint !== 'orders') {
            throw new WooFixtureNotFoundException("The orders simulator serves only orders, not {$endpoint}.");
        }

        $this->requests[] = ['page' => $page, 'window' => $window];

        if ($page === $this->failOnPage) {
            throw new RuntimeException('simulated Woo failure');
        }

        $matching = array_values(array_filter($this->orders, fn (array $o): bool => ($window?->modifiedAfter === null || $o['modified']->greaterThan($window->modifiedAfter))
            && ($window === null || $o['modified']->lessThan($window->modifiedBefore))));

        return new WooPage(
            array_map(fn (array $o): array => $o['payload'], array_slice($matching, ($page - 1) * $this->perPage, $this->perPage)),
            $page,
            intdiv(count($matching) + $this->perPage - 1, $this->perPage),
            count($matching),
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
