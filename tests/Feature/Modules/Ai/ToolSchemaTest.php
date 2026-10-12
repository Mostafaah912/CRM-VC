<?php

declare(strict_types=1);

use App\Modules\Ai\Drivers\GeminiDriver;
use App\Modules\Ai\Dto\AiMessage;
use App\Modules\Ai\Dto\AiRequest;
use App\Modules\Ai\Services\AiCostCalculator;
use App\Modules\Ai\Tools\ToolCatalog;
use App\Modules\Ai\Tools\ToolRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| P7-04 — the model-facing schema of every tool stays inside the JSON-Schema subset Gemini accepts
| (type/properties/enum/required/items/description), and matches the validation rules that are really enforced.
*/

const SCHEMA_KEYS = ['type', 'properties', 'enum', 'required', 'items', 'description'];
const SCHEMA_TYPES = ['object', 'string', 'integer', 'number', 'boolean', 'array'];

function assertSubset(array $node, string $path): void
{
    foreach ($node as $key => $value) {
        expect(in_array($key, SCHEMA_KEYS, true))->toBeTrue("{$path}.{$key} is outside the allowed JSON Schema subset");
    }

    expect(in_array($node['type'], SCHEMA_TYPES, true))->toBeTrue("{$path}.type");

    foreach ((array) ($node['properties'] ?? []) as $name => $child) {
        expect($child)->toBeArray()->toHaveKey('type')->toHaveKey('description');
        assertSubset($child, "{$path}.{$name}");
    }
}

it('keeps every tool schema inside the Gemini-safe JSON Schema subset', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->definitions())->toHaveCount(count(ToolCatalog::TOOLS));

    foreach ($registry->definitions() as $definition) {
        expect($definition->name)->toMatch('/^[a-z_]{1,60}$/')   // ai_tool_calls.tool_name is varchar(60)
            ->and($definition->description)->not->toBe('')->and(strlen($definition->description))->toBeLessThan(500)
            ->and($definition->parameters['type'])->toBe('object');

        $props = (array) $definition->parameters['properties'];

        foreach ($props as $name => $child) {
            assertSubset($child, "{$definition->name}.{$name}");
        }

        expect(array_diff($definition->parameters['required'] ?? [], array_keys($props)))->toBe([])
            ->and(json_encode($definition->parameters))->not->toContain('$ref')->not->toContain('additionalProperties');
    }
});

it('matches every schema property with a validation rule, and vice versa', function () {
    foreach (ToolCatalog::TOOLS as $class) {
        $tool = app($class);

        expect(array_keys($tool->rules()))->toEqualCanonicalizing(array_keys($tool->properties()), $tool->name());

        foreach ($tool->properties() as $name => $schema) {
            if (isset($schema['enum'])) {
                expect($tool->rules()[$name])->toBe('in:'.implode(',', $schema['enum']), "{$tool->name()}.{$name} enum != rule");
            }
        }
    }
});

it('serialises through the Gemini driver as valid functionDeclarations (empty properties become {})', function () {
    Http::preventStrayRequests();
    Http::fake(['gemini.test/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []])]);

    (new GeminiDriver('test-key', 'https://gemini.test/v1beta', 'test-model', 30, 0, [1], new AiCostCalculator))
        ->generate(new AiRequest('s', [AiMessage::user('q')], app(ToolRegistry::class)->definitions()));

    Http::assertSent(function (Request $r) {
        $declarations = json_decode($r->body(), true)['tools'][0]['functionDeclarations'];
        $noArgs = collect($declarations)->firstWhere('name', 'get_rfm_distribution');

        return count($declarations) === 12 && $noArgs['parameters'] === ['type' => 'object', 'properties' => []]
            && str_contains($r->body(), '"properties":{}');
    });
});
