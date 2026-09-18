<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Exceptions\WooConfigurationException;
use App\Modules\Sync\Exceptions\WooMalformedResponseException;
use App\Modules\Sync\Exceptions\WooRequestException;
use App\Modules\Sync\Support\SyncWindow;
use App\Modules\Sync\Support\WooPage;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * Transport only: GET over HTTPS Basic auth, rate limit, retry (PRD §10). No sync logic.
 *
 * Retryable: 429, 500, 502, 503, 504, connection failures and timeouts — backed off along the
 * configured ladder (2s, 8s, 30s, 120s), at most `max_retries` times; a 429 Retry-After wins
 * over the ladder. Every other non-2xx status is terminal. Each attempt, retries included,
 * takes a rate-limit token first.
 *
 * Logs carry the endpoint, status and query parameter NAMES — never credentials, headers,
 * query values (they can hold customer data) or a transport error text (cURL echoes the URL).
 */
final class HttpWooClient implements WooClient
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** @var list<int> */
    private readonly array $backoffSeconds;

    /**
     * @param  array<array-key, int>  $backoffSeconds
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $key,
        private readonly string $secret,
        private readonly string $version,
        private readonly int $timeout,
        private readonly int $perPage,
        private readonly int $maxRetries,
        array $backoffSeconds,
        private readonly RedisTokenBucket $rateLimiter,
    ) {
        if ($baseUrl === '' || $key === '' || $secret === '' || $version === '') {
            throw new WooConfigurationException('WooCommerce is not configured: woo.base_url, woo.key, woo.secret and woo.version are required.');
        }

        if (strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new WooConfigurationException('woo.base_url must use HTTPS: Basic auth credentials are never sent over plain HTTP.');
        }

        if ($timeout < 1 || $perPage < 1 || $perPage > 100 || $maxRetries < 0) {
            throw new WooConfigurationException('woo.timeout, woo.per_page (1-100) and woo.max_retries must be valid.');
        }

        $ladder = array_values($backoffSeconds);

        if ($ladder === [] || min($ladder) < 1) {
            throw new WooConfigurationException('woo.retry_backoff_seconds must be a non-empty list of positive seconds.');
        }

        $this->backoffSeconds = $ladder;
    }

    public function page(string $endpoint, int $page, ?SyncWindow $window = null, array $query = []): WooPage
    {
        $this->assertEndpoint($endpoint);

        if ($page < 1) {
            throw new InvalidArgumentException('page must be at least 1.');
        }

        // Reserved parameters come last so a caller filter can never override pagination or the frozen window.
        $params = [...$query, ...($window?->toQuery() ?? []), 'page' => $page, 'per_page' => $this->perPage];

        return $this->decodePage($endpoint, $page, $this->fetch($endpoint, $params));
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
     * @param  array<string, scalar>  $params
     */
    private function fetch(string $endpoint, array $params): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $this->rateLimiter->acquire();

            $response = null;
            $status = null;

            try {
                $response = $this->pendingRequest()->get($endpoint, $params);
                $status = $response->status();

                if ($response->successful()) {
                    return $response;
                }
            } catch (ConnectionException $e) {
                // Deliberately not logged/rethrown verbatim: cURL's text contains the full URL.
            }

            $retryable = $status === null || in_array($status, self::RETRYABLE_STATUSES, true);

            if (! $retryable || $attempt > $this->maxRetries) {
                $this->fail($endpoint, $params, $status, $attempt, $retryable, $response);
            }

            $delay = ($status === 429 && $response !== null ? $this->retryAfterSeconds($response) : null)
                ?? $this->backoffSeconds[min($attempt, count($this->backoffSeconds)) - 1];

            Log::warning('Woo request failed, will retry', [
                'endpoint' => $endpoint,
                'query_keys' => array_keys($params),
                'status' => $status,
                'attempt' => $attempt,
                'delay_seconds' => $delay,
            ]);

            Sleep::for($delay)->seconds();
        }
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function fail(string $endpoint, array $params, ?int $status, int $attempts, bool $retryable, ?Response $response): never
    {
        $wooCode = $response === null ? null : $this->wooErrorCode($response);
        $what = $status === null ? 'a connection failure or timeout' : "HTTP {$status}";
        $ending = $retryable ? "retries exhausted after {$attempts} attempts" : 'not retryable';

        Log::error('Woo request failed', [
            'endpoint' => $endpoint,
            'query_keys' => array_keys($params),
            'status' => $status,
            'attempts' => $attempts,
            'retryable' => $retryable,
            'woo_code' => $wooCode,
        ]);

        throw new WooRequestException(
            "Woo GET {$endpoint} failed with {$what} ({$ending}).",
            $endpoint,
            $status,
            $attempts,
            $retryable,
            $wooCode,
        );
    }

    private function decodePage(string $endpoint, int $page, Response $response): WooPage
    {
        $items = $response->json();

        if (! is_array($items) || ! array_is_list($items)) {
            throw new WooMalformedResponseException("Woo {$endpoint} did not return a JSON list.", $endpoint);
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new WooMalformedResponseException("Woo {$endpoint} returned a list with non-object items.", $endpoint);
            }
        }

        $totalPages = $response->header('X-WP-TotalPages');

        if (! ctype_digit($totalPages)) {
            throw new WooMalformedResponseException("Woo {$endpoint} sent no usable X-WP-TotalPages header.", $endpoint);
        }

        if ($items !== [] && (int) $totalPages < $page) {
            throw new WooMalformedResponseException("Woo {$endpoint} returned items on page {$page} beyond X-WP-TotalPages.", $endpoint);
        }

        $total = $response->header('X-WP-Total');

        return new WooPage($items, $page, (int) $totalPages, ctype_digit($total) ? (int) $total : null);
    }

    /** Seconds from a usable Retry-After (delta-seconds or HTTP-date); null when absent, invalid, or not in the future. */
    private function retryAfterSeconds(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        if (ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $value, new DateTimeZone('UTC'));

        if ($date === false) {
            return null;
        }

        $seconds = $date->getTimestamp() - now()->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    private function wooErrorCode(Response $response): ?string
    {
        $code = $response->json('code');

        return is_string($code) ? substr($code, 0, 100) : null;
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/wp-json/'.trim($this->version, '/'))
            ->withBasicAuth($this->key, $this->secret)
            ->acceptJson()
            ->timeout($this->timeout)
            ->withoutRedirecting()
            ->withUserAgent('HeyMode-CustomerOS');
    }

    /** Endpoints are relative API paths only — anything else could send credentials to another host. */
    private function assertEndpoint(string $endpoint): void
    {
        if (preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $endpoint) !== 1) {
            throw new InvalidArgumentException('Woo endpoint must be a relative path such as "orders" or "products/categories".');
        }
    }
}
