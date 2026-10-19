<?php

declare(strict_types=1);

namespace App\Modules\Ai\Drivers;

use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Enums\AiMessageRole;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Gemini generateContent (REST, API key in the x-goog-api-key header — never the URL). Function calling
 * and structured output (responseMimeType + responseSchema). Request/response shapes follow Google's
 * documented format but have NOT been confirmed against the live API (no key in P7-02) — see sprint-7.md.
 */
final class GeminiDriver extends HttpAiDriver
{
    private const BLOCKED_FINISH_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'];

    protected function name(): string
    {
        return AiDriver::Gemini->value;
    }

    protected function buildRequest(AiRequest $request): array
    {
        $generationConfig = [
            'maxOutputTokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->responseSchema !== null) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $this->schema($request->responseSchema);
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $request->systemPrompt]]],
            'contents' => $this->contents($request->messages),
            'generationConfig' => $generationConfig,
        ];

        if ($request->tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map(fn ($tool) => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $this->schema($tool->parameters),
            ], $request->tools)]];
        }

        return [
            'url' => "{$this->baseUrl}/models/{$this->model}:generateContent",
            'headers' => ['x-goog-api-key' => $this->apiKey],
            'body' => $body,
        ];
    }

    protected function parseResponse(AiRequest $request, Response $response, int $durationMs): AiResponse
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw $this->invalid('not a JSON object');
        }

        // Usage first: every refusal/cut-off below was still billed, and the ledger must say so.
        $usage = (array) ($data['usageMetadata'] ?? []);
        $in = (int) ($usage['promptTokenCount'] ?? 0);
        // Billed output = everything that is not the prompt: totalTokenCount - promptTokenCount (Google: total = prompt + tool-use prompt + thoughts +
        // candidates), or candidates + thoughts when that is larger — whichever is bigger, so hidden thinking is never under-counted even when
        // thoughtsTokenCount is not reported separately. `reasoning` keeps the thoughts figure (derived from the remainder when not reported).
        $candidates = (int) ($usage['candidatesTokenCount'] ?? 0);
        $thoughts = (int) ($usage['thoughtsTokenCount'] ?? 0);
        $out = max($candidates + $thoughts, max(0, (int) ($usage['totalTokenCount'] ?? 0) - $in));
        $reasoning = max($thoughts, $out - $candidates);

        // Numbers only, never content: which fields Google actually returns decides how usage is read (see sprint-7.md).
        Log::info('Gemini usageMetadata', array_filter($usage, 'is_int'));
        $usageOnly = $this->billedUsage($in, $out, $durationMs, $reasoning);
        $billed = $in + $out > 0 ? $usageOnly : null;   // nothing to record when the answer carries no usage numbers

        $blockReason = $data['promptFeedback']['blockReason'] ?? null;

        if (is_string($blockReason)) {
            throw $this->blocked('AI provider [gemini] blocked the prompt.', $response->status(), $billed);
        }

        $candidate = $data['candidates'][0] ?? null;

        if (! is_array($candidate)) {
            throw $this->invalid('no candidates', $billed);
        }

        $finish = is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : null;

        if (in_array($finish, self::BLOCKED_FINISH_REASONS, true)) {
            throw $this->blocked('AI provider [gemini] blocked the answer.', $response->status(), $billed);
        }

        if ($finish === 'MAX_TOKENS') {
            throw $this->invalid('the answer was cut off at maxOutputTokens', $billed);
        }

        $text = '';
        $toolCalls = [];

        foreach ((array) ($candidate['content']['parts'] ?? []) as $index => $part) {
            if (! is_array($part)) {
                continue;
            }

            if (isset($part['functionCall']) && is_array($part['functionCall']) && is_string($part['functionCall']['name'] ?? null)) {
                $call = $part['functionCall'];
                $toolCalls[] = new AiToolCall(
                    is_string($call['id'] ?? null) ? $call['id'] : "{$call['name']}_{$index}",
                    $call['name'],
                    is_array($call['args'] ?? null) ? $call['args'] : [],
                );
            } elseif (is_string($part['text'] ?? null) && ($part['thought'] ?? false) !== true) {
                $text .= $part['text'];
            }
        }

        if ($text === '' && $toolCalls === []) {
            throw $this->invalid('no text and no tool call', $billed);
        }

        $json = null;

        if ($request->responseSchema !== null && $toolCalls === []) {
            $json = $this->decodeJsonObject($text);

            if ($json === null) {
                throw $this->invalid('structured output was not a JSON object', $billed);
            }
        }

        return new AiResponse(
            $text === '' ? null : $text,
            $json,
            $toolCalls,
            $in,
            $out,
            $usageOnly->costUsd,
            $this->model,
            $this->name(),
            $durationMs,
            $reasoning,
        );
    }

    /**
     * @param  list<AiMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function contents(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            if ($message->role === AiMessageRole::Tool) {
                $decoded = json_decode($message->content, true);
                $part = ['functionResponse' => [
                    'name' => (string) $message->toolName,
                    'response' => is_array($decoded) && ! array_is_list($decoded) ? $decoded : ['result' => $decoded ?? $message->content],
                ]];
                $last = array_key_last($contents);

                // Consecutive tool results answer one model turn: Gemini wants them in a single content.
                if ($last !== null && ($contents[$last]['_tool'] ?? false)) {
                    $contents[$last]['parts'][] = $part;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part], '_tool' => true];
                }

                continue;
            }

            $parts = $message->content !== '' ? [['text' => $message->content]] : [];

            foreach ($message->toolCalls as $call) {
                $parts[] = ['functionCall' => ['name' => $call->name, 'args' => (object) $call->arguments]];
            }

            $contents[] = ['role' => $message->role === AiMessageRole::Assistant ? 'model' : 'user', 'parts' => $parts];
        }

        return array_map(function (array $content): array {
            unset($content['_tool']);

            return $content;
        }, $contents);
    }

    /**
     * Gemini accepts an OpenAPI-style subset of JSON Schema: these keywords make it return HTTP 400.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function schema(array $schema): array
    {
        $clean = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, ['additionalProperties', '$schema', '$id'], true)) {
                continue;
            }

            $clean[$key] = match (true) {
                $key === 'properties' && $value === [] => (object) [],
                is_array($value) => $this->schema($value),
                default => $value,
            };
        }

        return $clean;
    }
}
