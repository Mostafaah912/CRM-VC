<?php

declare(strict_types=1);

namespace App\Modules\Ai\Drivers;

use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiResponse;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Enums\AiDriver;
use App\Modules\Ai\Enums\AiMessageRole;
use App\Modules\Ai\Exceptions\AiContentBlockedException;
use Illuminate\Http\Client\Response;

/** OpenAI Chat Completions. Simple and UNVERIFIED against the live API (P7-02). */
final class OpenAiDriver extends HttpAiDriver
{
    protected function name(): string
    {
        return AiDriver::OpenAi->value;
    }

    protected function buildRequest(AiRequest $request): array
    {
        $messages = [['role' => 'system', 'content' => $request->systemPrompt]];

        foreach ($request->messages as $message) {
            if ($message->role === AiMessageRole::Tool) {
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string) $message->toolCallId, 'content' => $message->content];

                continue;
            }

            $entry = ['role' => $message->role->value, 'content' => $message->content === '' ? null : $message->content];

            if ($message->toolCalls !== []) {
                $entry['tool_calls'] = array_map(fn (AiToolCall $call) => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => json_encode((object) $call->arguments)],
                ], $message->toolCalls);
            }

            $messages[] = $entry;
        }

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_completion_tokens' => $request->maxOutputTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(fn ($tool) => [
                'type' => 'function',
                'function' => ['name' => $tool->name, 'description' => $tool->description, 'parameters' => $tool->parameters],
            ], $request->tools);
        }

        if ($request->responseSchema !== null) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'analyst_output', 'schema' => $request->responseSchema]];
        }

        return [
            'url' => "{$this->baseUrl}/chat/completions",
            'headers' => ['Authorization' => "Bearer {$this->apiKey}"],
            'body' => $body,
        ];
    }

    protected function parseResponse(AiRequest $request, Response $response, int $durationMs): AiResponse
    {
        $data = $response->json();
        $choice = is_array($data) ? ($data['choices'][0] ?? null) : null;

        if (! is_array($choice) || ! is_array($choice['message'] ?? null)) {
            throw $this->invalid('no choices');
        }

        $finish = $choice['finish_reason'] ?? null;

        if ($finish === 'content_filter' || is_string($choice['message']['refusal'] ?? null)) {
            throw new AiContentBlockedException('AI provider [openai] refused to answer.', $this->name(), $response->status());
        }

        if ($finish === 'length') {
            throw $this->invalid('the answer was cut off at the token limit');
        }

        $text = is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '';
        $toolCalls = [];

        foreach ((array) ($choice['message']['tool_calls'] ?? []) as $call) {
            $function = is_array($call) ? ($call['function'] ?? null) : null;

            if (! is_array($function) || ! is_string($call['id'] ?? null) || ! is_string($function['name'] ?? null)) {
                throw $this->invalid('malformed tool call');
            }

            $arguments = json_decode((string) ($function['arguments'] ?? '{}'), true);

            if (! is_array($arguments)) {
                throw $this->invalid('tool call arguments are not JSON');
            }

            $toolCalls[] = new AiToolCall($call['id'], $function['name'], $arguments);
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

        $in = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $out = (int) ($data['usage']['completion_tokens'] ?? 0);

        return new AiResponse($text === '' ? null : $text, $json, $toolCalls, $in, $out, $this->costs->cost($this->name(), $this->model, $in, $out), $this->model, $this->name(), $durationMs);
    }
}
