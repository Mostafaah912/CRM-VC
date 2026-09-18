<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use InvalidArgumentException;

/**
 * One recorded Woo exchange, as static data: the request the client is asked for
 * (endpoint, page, extra filters) and the HTTP response Woo gave (status, headers, body).
 * `attempts` is how many requests HttpWooClient spent on it before giving up (1 for a terminal
 * failure, 1 + max_retries for an exhausted retryable one) — a recorded fact, not computed here.
 *
 * The frozen window is deliberately NOT part of the request key: it derives from the wall clock,
 * so a fixture pinned to it could never match twice. FakeWooClient records it for assertions.
 */
final readonly class WooFixture
{
    /** Relative API paths only — the same rule HttpWooClient enforces. */
    public const ENDPOINT_PATTERN = '#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#';

    /** @var array<string, string> */
    public array $query;

    /** @var array<string, string> lower-cased header names */
    public array $headers;

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<array-key, mixed>  $headers
     */
    public function __construct(
        public string $endpoint,
        public int $page,
        array $query,
        public int $status,
        array $headers,
        public mixed $body,
        public int $attempts = 1,
        public string $description = '',
    ) {
        if (preg_match(self::ENDPOINT_PATTERN, $endpoint) !== 1) {
            throw new InvalidArgumentException('Woo fixture endpoint must be a relative path such as "orders".');
        }

        if ($page < 1 || $attempts < 1 || $status < 100 || $status > 599) {
            throw new InvalidArgumentException("Woo fixture for {$endpoint} needs page >= 1, attempts >= 1 and an HTTP status 100-599.");
        }

        $this->query = self::normalizeQuery($query);
        $this->headers = self::normalizeHeaders($headers, $endpoint);
    }

    /**
     * Build from the recorded JSON shape:
     * { description?, request: {endpoint, page, query?}, response: {status, headers?, body, attempts?} }
     *
     * @param  array<array-key, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        $request = $definition['request'] ?? null;
        $response = $definition['response'] ?? null;

        if (! is_array($request) || ! is_array($response)) {
            throw new InvalidArgumentException('Woo fixture needs both a "request" and a "response" object.');
        }

        $endpoint = $request['endpoint'] ?? null;
        $query = $request['query'] ?? [];
        $headers = $response['headers'] ?? [];
        $description = $definition['description'] ?? '';

        if (! is_string($endpoint) || ! is_int($request['page'] ?? null) || ! is_array($query)) {
            throw new InvalidArgumentException('Woo fixture request needs a string endpoint, an integer page and an object query.');
        }

        if (! is_int($response['status'] ?? null) || ! is_array($headers) || ! array_key_exists('body', $response)
            || ! is_int($response['attempts'] ?? 1) || ! is_string($description)) {
            throw new InvalidArgumentException("Woo fixture response for {$endpoint} needs an integer status, a headers object and a body.");
        }

        return new self(
            $endpoint,
            $request['page'],
            $query,
            $response['status'],
            $headers,
            $response['body'],
            $response['attempts'] ?? 1,
            $description,
        );
    }

    /** Header lookup is case-insensitive, like HTTP; '' when absent (mirrors Illuminate's Response::header). */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function key(): string
    {
        return self::keyFor($this->endpoint, $this->page, $this->query);
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    public static function keyFor(string $endpoint, int $page, array $query): string
    {
        return "{$endpoint} page {$page}".($query === [] ? '' : ' ?'.http_build_query(self::normalizeQuery($query)));
    }

    /**
     * Filters compare as strings, in key order — 11 and '11' are the same request on the wire.
     *
     * @param  array<array-key, mixed>  $query
     * @return array<string, string>
     */
    public static function normalizeQuery(array $query): array
    {
        $normalized = [];

        foreach ($query as $name => $value) {
            if (! is_scalar($value)) {
                throw new InvalidArgumentException('Woo request filters must be scalar values.');
            }

            $normalized[(string) $name] = (string) $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers, string $endpoint): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("Woo fixture for {$endpoint} has a non-string header value.");
            }

            $normalized[strtolower((string) $name)] = $value;
        }

        return $normalized;
    }
}
