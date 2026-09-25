<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Exceptions\WooFixtureNotFoundException;
use App\Modules\Sync\Exceptions\WooMalformedResponseException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooFixture;
use App\Modules\Sync\Support\WooPage;
use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministic, network-free WooClient for tests (CLAUDE.md §8): it REPLAYS recorded exchanges.
 * It is not a second HttpWooClient — no HTTP, retry, Retry-After or rate limiting happens here;
 * a fixture already records how the real client ended (attempts) and this only surfaces that
 * outcome as the same page or exception. tests/Feature/.../FakeWooClientParityTest keeps the two
 * in agreement. Read-only like the interface; never bound by the application, tests bind it.
 *
 * A request no fixture matches throws WooFixtureNotFoundException — never an empty page.
 */
final class FakeWooClient implements WooClient
{
    /** The PRD §10 statuses HttpWooClient retries; only used to label a replayed failure. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** @var array<string, WooFixture> */
    private array $fixtures = [];

    /** @var list<array{endpoint: string, page: int, window: ?SyncWindow, query: array<string, scalar>}> */
    private array $requests = [];

    /**
     * @param  iterable<WooFixture>  $fixtures
     */
    public function __construct(iterable $fixtures = [])
    {
        foreach ($fixtures as $fixture) {
            if (isset($this->fixtures[$fixture->key()])) {
                throw new LogicException("Two recorded Woo fixtures match the same request: GET {$fixture->key()}.");
            }

            $this->fixtures[$fixture->key()] = $fixture;
        }
    }

    public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
    {
        if (preg_match(WooFixture::ENDPOINT_PATTERN, $endpoint) !== 1) {
            throw new InvalidArgumentException('Woo endpoint must be a relative path such as "orders" or "products/categories".');
        }

        if ($page < 1) {
            throw new InvalidArgumentException('page must be at least 1.');
        }

        $this->requests[] = ['endpoint' => $endpoint, 'page' => $page, 'window' => $window, 'query' => $query];

        $fixture = $this->fixtures[WooFixture::keyFor($endpoint, $page, $query)]
            ?? throw new WooFixtureNotFoundException($this->describeMiss($endpoint, $page, $query));

        return $this->replay($fixture);
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

    /**
     * Every request made so far, in order, so a test can assert what the code under test asked for
     * (including the frozen window, which fixtures deliberately do not match on).
     *
     * @return list<array{endpoint: string, page: int, window: ?SyncWindow, query: array<string, scalar>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    private function replay(WooFixture $fixture): WooPage
    {
        if ($fixture->status < 200 || $fixture->status > 299) {
            $body = $fixture->body;
            $code = is_array($body) && is_string($body['code'] ?? null) ? substr($body['code'], 0, 100) : null;
            $retryable = in_array($fixture->status, self::RETRYABLE_STATUSES, true);

            throw new WooRequestException(
                "Recorded Woo fixture replays HTTP {$fixture->status} for GET {$fixture->endpoint} (".($retryable ? "retries exhausted after {$fixture->attempts} attempts" : 'not retryable').').',
                $fixture->endpoint,
                $fixture->status,
                $fixture->attempts,
                $retryable,
                $code,
            );
        }

        $items = $this->itemsOf($fixture->body);
        $totalPages = $fixture->header('X-WP-TotalPages');

        if ($items === null) {
            throw new WooMalformedResponseException("Recorded Woo fixture for {$fixture->endpoint} is not a JSON list of objects.", $fixture->endpoint);
        }

        if (! ctype_digit($totalPages)) {
            throw new WooMalformedResponseException("Recorded Woo fixture for {$fixture->endpoint} has no usable X-WP-TotalPages header.", $fixture->endpoint);
        }

        if ($items !== [] && (int) $totalPages < $fixture->page) {
            throw new WooMalformedResponseException("Recorded Woo fixture for {$fixture->endpoint} holds items beyond X-WP-TotalPages.", $fixture->endpoint);
        }

        $total = $fixture->header('X-WP-Total');

        return new WooPage($items, $fixture->page, (int) $totalPages, ctype_digit($total) ? (int) $total : null);
    }

    /** @return list<array<array-key, mixed>>|null null when the body is not a list of objects */
    private function itemsOf(mixed $body): ?array
    {
        if (! is_array($body) || ! array_is_list($body)) {
            return null;
        }

        $items = [];

        foreach ($body as $item) {
            if (! is_array($item)) {
                return null;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Test-facing hint. Filter KEYS only — values can be phone numbers or secrets.
     *
     * @param  array<array-key, mixed>  $query
     */
    private function describeMiss(string $endpoint, int $page, array $query): string
    {
        $filters = fn (array $names): string => $names === [] ? 'no filters' : 'filters: '.implode(', ', $names);

        $message = "No recorded Woo fixture for GET {$endpoint} page {$page} (".$filters(array_map('strval', array_keys($query))).').';
        $recorded = array_filter($this->fixtures, fn (WooFixture $f) => $f->endpoint === $endpoint);

        if ($recorded === []) {
            $endpoints = array_values(array_unique(array_map(fn (WooFixture $f) => $f->endpoint, $this->fixtures)));
            sort($endpoints);

            return $message.' Nothing is recorded for this endpoint. Recorded endpoints: '.($endpoints === [] ? 'none' : implode(', ', $endpoints)).'.';
        }

        $known = array_map(fn (WooFixture $f) => "page {$f->page} (".$filters(array_keys($f->query)).')', array_values($recorded));
        sort($known);

        return $message." Recorded for '{$endpoint}': ".implode('; ', $known).'.';
    }
}
