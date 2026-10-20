<?php

declare(strict_types=1);

namespace App\Modules\Ai\Drivers;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Exceptions\AiAuthenticationException;
use App\Modules\Ai\Exceptions\AiConfigurationException;
use App\Modules\Ai\Exceptions\AiContentBlockedException;
use App\Modules\Ai\Exceptions\AiInvalidResponseException;
use App\Modules\Ai\Exceptions\AiProviderException;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Exceptions\AiTimeoutException;
use App\Modules\Ai\Services\AiCostCalculator;
use App\Modules\Ai\Services\AiProvider;
use App\Modules\Ai\Support\RawResponseCapture;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Shared transport: HTTP, short retry for transient failures only (429 / 5xx / timeout), status → domain
 * exception mapping. Logs and exception messages carry provider, status and attempt — never the key (it
 * lives in a header, never the URL), the prompt, a response body or a transport error text.
 */
abstract class HttpAiDriver implements AiProvider
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504, 529];

    /** @var list<int> */
    private readonly array $backoffSeconds;

    /** @param array<array-key, int> $backoffSeconds */
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly string $model,
        protected readonly int $timeout,
        protected readonly int $maxRetries,
        array $backoffSeconds,
        protected readonly AiCostCalculator $costs,
    ) {
        if ($apiKey === '' || $baseUrl === '' || $model === '') {
            throw new AiConfigurationException(
                "AI provider [{$this->name()}] is not configured: its API key, base URL and AI_MODEL are required.",
                $this->name(),
            );
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,80}$/', $model) !== 1) {
            throw new AiConfigurationException('AI_MODEL contains characters that are not valid in a model id.', $this->name());
        }

        if ($timeout < 1 || $maxRetries < 0 || $backoffSeconds === []) {
            throw new AiConfigurationException('ai.timeout, ai.max_retries and ai.retry_backoff_seconds must be valid.', $this->name());
        }

        $this->backoffSeconds = array_values($backoffSeconds);
    }

    abstract protected function name(): string;

    /** @return array{url: string, headers: array<string, string>, body: array<string, mixed>} */
    abstract protected function buildRequest(AiRequest $request): array;

    abstract protected function parseResponse(AiRequest $request, Response $response, int $durationMs): AiResponse;

    /** A 2xx answer that is nevertheless a refusal/cut-off is raised by parseResponse(). */
    public function generate(AiRequest $request): AiResponse
    {
        $built = $this->buildRequest($request);
        $started = hrtime(true);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withHeaders($built['headers'])
                    ->acceptJson()
                    ->asJson()
                    ->timeout($this->timeout)
                    ->post($built['url'], $built['body']);

                if ($response->successful()) {
                    app(RawResponseCapture::class)->store($this->name(), $response->body());   // no-op unless --debug-raw armed it

                    return $this->parseResponse($request, $response, (int) ((hrtime(true) - $started) / 1_000_000));
                }

                $failure = $this->failureForStatus($response->status(), $this->errorCode($response), $attempt);
            } catch (ConnectionException) {
                // Not rethrown or logged verbatim: cURL's text contains the full URL.
                $failure = new AiTimeoutException("AI provider [{$this->name()}] timed out or the connection failed.", $this->name(), null, true, $attempt);
            }

            if (! $failure->retryable || $attempt > $this->maxRetries) {
                Log::error('AI request failed', ['provider' => $this->name(), 'status' => $failure->status, 'attempts' => $attempt]);

                throw $failure;
            }

            $delay = $this->backoffSeconds[min($attempt, count($this->backoffSeconds)) - 1];

            Log::warning('AI request failed, will retry', ['provider' => $this->name(), 'status' => $failure->status, 'attempt' => $attempt, 'delay_seconds' => $delay]);

            Sleep::for($delay)->seconds();
        }
    }

    private function failureForStatus(int $status, ?string $code, int $attempt): AiProviderException
    {
        $name = $this->name();
        $suffix = $code === null ? '' : " ({$code})";

        return match (true) {
            $status === 401, $status === 403 => new AiAuthenticationException("AI provider [{$name}] rejected the credentials: HTTP {$status}{$suffix}.", $name, $status, false, $attempt),
            $status === 429 => new AiRateLimitException("AI provider [{$name}] rate-limited the request: HTTP 429{$suffix}.", $name, 429, true, $attempt),
            in_array($status, self::RETRYABLE_STATUSES, true) => new AiProviderException("AI provider [{$name}] had a transient failure: HTTP {$status}{$suffix}.", $name, $status, true, $attempt),
            default => new AiProviderException("AI provider [{$name}] rejected the request: HTTP {$status}{$suffix}.", $name, $status, false, $attempt),
        };
    }

    /** Only an enum-looking provider error code is ever surfaced — error messages can echo the prompt. */
    private function errorCode(Response $response): ?string
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        $code = is_array($error) ? ($error['status'] ?? $error['type'] ?? $error['code'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Za-z_]{1,40}$/', $code) === 1 ? $code : null;
    }

    protected function invalid(string $why, ?AiResponse $billed = null): AiInvalidResponseException
    {
        return new AiInvalidResponseException("AI provider [{$this->name()}] returned an unusable response: {$why}.", $this->name(), null, false, 1, $billed);
    }

    /** The usage of an answer that cannot be used, as a text-less AiResponse (see AiProviderException::$billed). */
    protected function billedUsage(int $in, int $out, int $durationMs, int $reasoning = 0): AiResponse
    {
        return new AiResponse(null, null, [], $in, $out, $this->costs->cost($this->name(), $this->model, $in, $out), $this->model, $this->name(), $durationMs, $reasoning);
    }

    protected function blocked(string $message, int $status, ?AiResponse $billed = null): AiContentBlockedException
    {
        return new AiContentBlockedException($message, $this->name(), $status, false, 1, $billed);
    }

    /** @return array<string, mixed>|null */
    protected function decodeJsonObject(?string $text): ?array
    {
        if ($text === null || $text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
