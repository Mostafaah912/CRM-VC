<?php

declare(strict_types=1);

use App\Modules\Ai\Drivers\AnthropicDriver;
use App\Modules\Ai\Drivers\OpenAiDriver;
use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Dto\AiToolCall;
use App\Modules\Ai\Dto\AiToolDefinition;
use App\Modules\Ai\Exceptions\AiAuthenticationException;
use App\Modules\Ai\Exceptions\AiContentBlockedException;
use App\Modules\Ai\Exceptions\AiInvalidResponseException;
use App\Modules\Ai\Exceptions\AiRateLimitException;
use App\Modules\Ai\Services\AiCostCalculator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| P7-02 — the two simple, UNVERIFIED drivers (PRD §25 "3 providers"): request building and parsing only.
| Http::preventStrayRequests() + Http::fake(): nothing here touches the network.
*/

function anthropic(): AnthropicDriver
{
    return new AnthropicDriver('test-anthropic-key', 'https://anthropic.test/v1', 'test-model', 30, 1, [1], new AiCostCalculator);
}

function openai(): OpenAiDriver
{
    return new OpenAiDriver('test-openai-key', 'https://openai.test/v1', 'test-model', 30, 1, [1], new AiCostCalculator);
}

function otherReq(?array $schema = null, bool $tools = false, bool $toolTurn = false): AiRequest
{
    $messages = $toolTurn
        ? [AiMessage::user('q'), AiMessage::assistant('', [new AiToolCall('c1', 'get_x', ['k' => 1])]), AiMessage::tool('c1', 'get_x', '{"v":1}')]
        : [AiMessage::user('hello')];

    return new AiRequest('system prompt', $messages, $tools ? [new AiToolDefinition('get_x', 'reads x')] : [], $schema, 256, 0.3);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config(['ai.pricing' => ['default' => ['input' => '1', 'output' => '2'], 'models' => []]]);
});

it('anthropic: builds the Messages request (headers, system + schema, tools, tool turn)', function () {
    Http::fake(['anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"a":1}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])]);

    anthropic()->generate(otherReq(schema: ['type' => 'object'], tools: true, toolTurn: true));

    Http::assertSent(function (Request $r) {
        $b = json_decode($r->body(), true);

        return $r->url() === 'https://anthropic.test/v1/messages'
            && $r->hasHeader('x-api-key', 'test-anthropic-key')
            && $r->hasHeader('anthropic-version', '2023-06-01')
            && $b['model'] === 'test-model' && $b['max_tokens'] === 256 && $b['temperature'] === 0.3
            && str_starts_with($b['system'], 'system prompt') && str_contains($b['system'], '"type":"object"')
            && $b['tools'][0]['name'] === 'get_x' && isset($b['tools'][0]['input_schema'])
            && $b['messages'][1]['content'][0]['type'] === 'tool_use' && $b['messages'][1]['content'][0]['input'] === ['k' => 1]
            && $b['messages'][2] === ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'c1', 'content' => '{"v":1}']]];
    });
});

it('anthropic: parses text, JSON, tool_use, tokens and cost', function () {
    Http::fake(['anthropic.test/*' => Http::sequence()
        ->push(['content' => [['type' => 'text', 'text' => '{"a":1}']], 'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 500_000]])
        ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'get_x', 'input' => ['k' => 2]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]),
    ]);

    $json = anthropic()->generate(otherReq(schema: ['type' => 'object']));
    $tool = anthropic()->generate(otherReq(tools: true));

    expect($json->json)->toBe(['a' => 1])->and($json->inputTokens)->toBe(1_000_000)->and($json->outputTokens)->toBe(500_000)
        ->and($json->costUsd)->toBe('2.00000')->and($json->provider)->toBe('anthropic')
        ->and($tool->toolCalls[0]->id)->toBe('tu1')->and($tool->toolCalls[0]->arguments)->toBe(['k' => 2]);
});

it('anthropic: maps refusal / cut-off / auth / rate limit', function () {
    Http::fake(['anthropic.test/*' => Http::sequence()
        ->push(['content' => [], 'stop_reason' => 'refusal'])
        ->push(['content' => [['type' => 'text', 'text' => 'x']], 'stop_reason' => 'max_tokens'])
        ->push(['error' => ['type' => 'authentication_error']], 401)
        ->push(['error' => ['type' => 'rate_limit_error']], 429)
        ->push(['error' => ['type' => 'rate_limit_error']], 429),
    ]);

    expect(fn () => anthropic()->generate(otherReq()))->toThrow(AiContentBlockedException::class)
        ->and(fn () => anthropic()->generate(otherReq()))->toThrow(AiInvalidResponseException::class)
        ->and(fn () => anthropic()->generate(otherReq()))->toThrow(AiAuthenticationException::class)
        ->and(fn () => anthropic()->generate(otherReq()))->toThrow(AiRateLimitException::class);
});

it('openai: builds the chat completion request (bearer, system message, schema, tools, tool turn)', function () {
    Http::fake(['openai.test/*' => Http::response(['choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4]])]);

    openai()->generate(otherReq(schema: ['type' => 'object'], tools: true, toolTurn: true));

    Http::assertSent(function (Request $r) {
        $b = json_decode($r->body(), true);

        return $r->url() === 'https://openai.test/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer test-openai-key')
            && $b['messages'][0] === ['role' => 'system', 'content' => 'system prompt']
            && $b['max_completion_tokens'] === 256
            && $b['response_format']['type'] === 'json_schema'
            && $b['tools'][0]['function']['name'] === 'get_x'
            && $b['messages'][2]['tool_calls'][0]['function']['arguments'] === '{"k":1}'
            && $b['messages'][3] === ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '{"v":1}'];
    });
});

it('openai: parses text, JSON, tool calls, tokens and cost', function () {
    Http::fake(['openai.test/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 2_000_000, 'completion_tokens' => 1_000_000]])
        ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'get_x', 'arguments' => '{"k":3}']]]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
    ]);

    $json = openai()->generate(otherReq(schema: ['type' => 'object']));
    $tool = openai()->generate(otherReq(tools: true));

    expect($json->json)->toBe(['a' => 1])->and($json->costUsd)->toBe('4.00000')->and($json->provider)->toBe('openai')
        ->and($tool->toolCalls[0]->id)->toBe('call_9')->and($tool->toolCalls[0]->arguments)->toBe(['k' => 3]);
});

it('openai: maps filter / cut-off / malformed tool call / auth', function () {
    Http::fake(['openai.test/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']]])
        ->push(['choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'length']]])
        ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'c', 'function' => ['name' => 'get_x', 'arguments' => 'not json']]]], 'finish_reason' => 'tool_calls']]])
        ->push(['error' => ['code' => 'invalid_api_key']], 401),
    ]);

    expect(fn () => openai()->generate(otherReq()))->toThrow(AiContentBlockedException::class)
        ->and(fn () => openai()->generate(otherReq()))->toThrow(AiInvalidResponseException::class)
        ->and(fn () => openai()->generate(otherReq()))->toThrow(AiInvalidResponseException::class)
        ->and(fn () => openai()->generate(otherReq()))->toThrow(AiAuthenticationException::class);
});
