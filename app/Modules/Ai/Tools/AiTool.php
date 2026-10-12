<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Modules\Ai\Dto\AiToolDefinition;

/**
 * A read-only, aggregate-only analyst tool. Subclasses return small arrays of numbers/labels — never a customer row,
 * name, phone, email, address or customer id. `parameters()` is the model-facing JSON Schema (only type, properties,
 * enum, required, items, description: the subset Gemini accepts); `rules()` is the authoritative Laravel validation
 * the registry enforces — ranges live there and are repeated in the descriptions.
 */
abstract class AiTool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /** @return array<string, array<string, mixed>> property name => JSON Schema fragment */
    public function properties(): array
    {
        return [];
    }

    /** @return list<string> */
    public function required(): array
    {
        return [];
    }

    /** @return array<string, string> Laravel rules per property */
    public function rules(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $arguments  validated, defaults NOT yet applied
     * @return array<string, mixed>
     */
    abstract public function run(array $arguments): array;

    public function definition(): AiToolDefinition
    {
        $schema = ['type' => 'object', 'properties' => $this->properties() === [] ? new \stdClass : $this->properties()];

        if ($this->required() !== []) {
            $schema['required'] = $this->required();
        }

        return new AiToolDefinition($this->name(), $this->description(), $schema);
    }
}
