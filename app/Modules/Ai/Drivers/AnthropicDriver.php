<?php

declare(strict_types=1);

namespace App\Modules\Ai\Drivers;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Enums\AiMessageRole;
use App\Modules\Ai\Exceptions\AiContentBlockedException;
use App\Modules\Ai\Services\AiCostCalculator;
use Illuminate\Http\Client\Response;

/**
 * Anthropic Messages API. Deliberately simple and UNVERIFIED against the live API (P7-02): structured
 * output is requested through the system prompt (no native schema mode used), then parsed as JSON.
 */
final class AnthropicDriver extends HttpAiDriver
{
    public function __construct(
        string $apiKey,
        string $baseUrl,
        string $model,
        int $timeout,
        int $maxRetries,
        array $backoffSeconds,
        AiCostCalculator $costs,
        private readonly string $version = '2023-06-01',
    ) {
        parent::__construct($apiKey, $baseUrl, $model, $timeout, $maxRetries, $backoffSeconds, $costs);
    }

    protected function name(): string
    {
        return AiDriver::Anthropic->value;
    }

    protected function buildRequest(AiRequest $request): array
    {
        $system = $request->systemPrompt;

        if ($request->responseSchema !== null) {
            $system .= "\n\nRespond ONLY with one JSON object matching this JSON Schema, no prose:\n".json_encode($request->responseSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === AiMessageRole::Tool) {
                $messages[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => (string) $message->toolCallId, 'content' => $message->content]]];

                continue;
            }

            $blocks = $message->content !== '' ? [['type' => 'text', 'text' => $message->content]] : [];

            foreach ($message->toolCalls as $call) {
                $blocks[] = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => (object) $call->arguments];
            }

            $messages[] = ['role' => $message->role->value, 'content' => $blocks];
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => min($request->temperature, 1.0),
            'system' => $system,
            'messages' => $messages,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(fn ($tool) => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->parameters,
            ], $request->tools);
        }

        return [
            'url' => "{$this->baseUrl}/messages",
            'headers' => ['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version],
            'body' => $body,
        ];
    }

    protected function parseResponse(AiRequest $request, Response $response, int $durationMs): AiResponse
    {
        $data = $response->json();

        if (! is_array($data) || ! is_array($data['content'] ?? null)) {
            throw $this->invalid('no content blocks');
        }

        $stop = $data['stop_reason'] ?? null;

        if ($stop === 'refusal') {
            throw new AiContentBlockedException('AI provider [anthropic] refused to answer.', $this->name(), $response->status());
        }

        if ($stop === 'max_tokens') {
            throw $this->invalid('the answer was cut off at max_tokens');
        }

        $text = '';
        $toolCalls = [];

        foreach ($data['content'] as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use' && is_string($block['id'] ?? null) && is_string($block['name'] ?? null)) {
                $toolCalls[] = new AiToolCall($block['id'], $block['name'], is_array($block['input'] ?? null) ? $block['input'] : []);
            }
        }

        if ($text === '' && $toolCalls === []) {
            throw $this->invalid('no text and no tool call');
        }

        $json = null;

        if ($request->responseSchema !== null && $toolCalls === []) {
            $json = $this->decodeJsonObject($text);

            if ($json === null) {
                throw $this->invalid('structured output was not a JSON object');
            }
        }

        $in = (int) ($data['usage']['input_tokens'] ?? 0);
        $out = (int) ($data['usage']['output_tokens'] ?? 0);

        return new AiResponse($text === '' ? null : $text, $json, $toolCalls, $in, $out, $this->costs->cost($this->name(), $this->model, $in, $out), $this->model, $this->name(), $durationMs);
    }
}
