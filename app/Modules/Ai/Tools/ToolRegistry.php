<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use App\Modules\Ai\Dto\AiToolDefinition;
use App\Modules\Ai\Dto\ToolResult;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The model-facing tool surface. execute() never throws: an unknown tool, bad/extra arguments, an oversized result or a
 * database failure all become a ToolResult with a model-safe `error`, so the model can correct itself. Error text never
 * echoes the input it rejects (it could carry an injection payload back into the prompt).
 */
final class ToolRegistry
{
    /** @var array<string, AiTool>|null */
    private ?array $tools = null;

    public function __construct(private readonly Container $container) {}

    /** @return list<AiToolDefinition> */
    public function definitions(): array
    {
        return array_values(array_map(fn (AiTool $t) => $t->definition(), $this->all()));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /** @param array<array-key, mixed> $arguments */
    public function execute(string $name, array $arguments): ToolResult
    {
        $started = hrtime(true);
        $elapsed = fn (): int => (int) ((hrtime(true) - $started) / 1_000_000);
        $tool = $this->all()[$name] ?? null;

        if ($tool === null) {
            return new ToolResult(self::safeName($name), [], [], 0, $elapsed(), 'Unknown tool. Call one of the tools you were given.');
        }

        try {
            $clean = $this->validate($tool, $arguments);
            $result = $tool->run($clean);
        } catch (ToolArgumentException $e) {
            return new ToolResult($name, [], [], 0, $elapsed(), $e->getMessage());
        } catch (Throwable $e) {
            // Class name only: a QueryException message carries SQL and bound values.
            Log::warning('AI tool failed', ['tool' => $name, 'exception' => $e::class]);

            return new ToolResult($name, [], [], 0, $elapsed(), 'The tool failed to run. Retry later or with different arguments.');
        }

        $size = strlen((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $limit = (int) config('ai.tools.max_result_bytes', 6144);

        if ($size > $limit) {
            return new ToolResult($name, $clean, [], $size, $elapsed(), "The result is too large ({$size} bytes, limit {$limit}). Narrow the arguments (fewer days, smaller limit).");
        }

        return new ToolResult($name, $clean, $result, $size, $elapsed());
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function validate(AiTool $tool, array $arguments): array
    {
        $allowed = array_keys($tool->properties());

        if (array_diff(array_map('strval', array_keys($arguments)), $allowed) !== []) {
            throw new ToolArgumentException('Unknown arguments are not allowed. Allowed: '.($allowed === [] ? 'none' : implode(', ', $allowed)).'.');
        }

        $maxLength = (int) config('ai.tools.max_string_length', 64);

        foreach ($arguments as $value) {
            if (is_array($value) || is_object($value) || (is_string($value) && mb_strlen($value) > $maxLength)) {
                throw new ToolArgumentException("Arguments must be scalars; strings at most {$maxLength} characters.");
            }
        }

        $rules = $tool->rules();

        foreach ($tool->required() as $required) {
            $rules[$required] = trim('required|'.($rules[$required] ?? ''), '|');
        }

        $validator = Validator::make($arguments, $rules);

        if ($validator->fails()) {
            throw new ToolArgumentException('Invalid arguments: '.implode(', ', $validator->errors()->keys()).'. Check the parameter types, ranges and allowed values in the tool schema.');
        }

        return $validator->validated();
    }

    /** @return array<string, AiTool> */
    private function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];

            foreach (ToolCatalog::TOOLS as $class) {
                $tool = $this->container->make($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    private static function safeName(string $name): string
    {
        return preg_match('/^[A-Za-z0-9_]{1,60}$/', $name) === 1 ? $name : 'unknown';
    }
}
