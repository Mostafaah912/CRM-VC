<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Exceptions\AiProviderException;
use Closure;
use LogicException;

/**
 * The test double every AI test uses (PRD §24: no test hits the network). Scripted answers are consumed in
 * order; every call is recorded. Needs no config, DB or HTTP. Bind it with `$this->app->instance(AiProvider::class, $fake)`.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<AiResponse|AiProviderException|Closure(AiRequest): AiResponse> */
    private array $queue = [];

    /** @var list<AiRequest> */
    private array $calls = [];

    /**
     * @param  array<string, mixed>|null  $json
     * @param  list<AiToolCall>  $toolCalls
     */
    public static function response(
        ?string $text = null,
        ?array $json = null,
        array $toolCalls = [],
        int $inputTokens = 100,
        int $outputTokens = 50,
        string $costUsd = '0.00000',
        string $model = 'fake-model',
    ): AiResponse {
        return new AiResponse($text ?? ($json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null), $json, $toolCalls, $inputTokens, $outputTokens, $costUsd, $model, 'fake', 1);
    }

    /** @param array<string, mixed> $json */
    public function replyJson(array $json, int $inputTokens = 100, int $outputTokens = 50): self
    {
        return $this->queue(self::response(json: $json, inputTokens: $inputTokens, outputTokens: $outputTokens));
    }

    public function replyText(string $text, int $inputTokens = 100, int $outputTokens = 50): self
    {
        return $this->queue(self::response(text: $text, inputTokens: $inputTokens, outputTokens: $outputTokens));
    }

    /** @param array<string, mixed> $arguments */
    public function replyToolCall(string $name, array $arguments = [], string $id = 'call_1'): self
    {
        return $this->queue(self::response(toolCalls: [new AiToolCall($id, $name, $arguments)]));
    }

    /** @param AiResponse|AiProviderException|Closure(AiRequest): AiResponse $next */
    public function queue(AiResponse|AiProviderException|Closure $next): self
    {
        $this->queue[] = $next;

        return $this;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->calls[] = $request;
        $next = array_shift($this->queue)
            ?? throw new LogicException('FakeAiProvider has no scripted response left for call #'.count($this->calls).'.');

        if ($next instanceof AiProviderException) {
            throw $next;
        }

        return $next instanceof Closure ? $next($request) : $next;
    }

    /** @return list<AiRequest> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function remaining(): int
    {
        return count($this->queue);
    }
}
