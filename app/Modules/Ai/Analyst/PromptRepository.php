<?php

declare(strict_types=1);

namespace App\Modules\Ai\Analyst;

use App\Modules\Ai\Dto\Prompt;
use App\Modules\Ai\Enums\AiInsightType;
use RuntimeException;

/**
 * Prompts live in resources/prompts/*.md, one file per analysis type AND version (the default version is chosen in config/ai.php 'prompts'), each with a `version` in its front matter
 * (.claude/rules/ai.md: changing a prompt bumps its version — tests/Feature/Modules/Ai/PromptVersionGuardTest.php enforces it).
 * File format: front matter (version, type), then `<!-- system -->` and `<!-- user -->` sections; `{{context}}` marks where the
 * data block goes in the user message.
 */
final class PromptRepository
{
    /** The prompt currently IN USE for a type: the version named in config('ai.prompts'). Older versions stay on disk (and in the lock) untouched. */
    public function for(AiInsightType $type): Prompt
    {
        $wanted = config("ai.prompts.{$type->value}");

        foreach ($this->all() as $prompt) {
            if ($prompt->type === $type->value && $prompt->version === $wanted) {
                return $prompt;
            }
        }

        throw new RuntimeException("No prompt file [{$wanted}] for analysis type [{$type->value}] (config ai.prompts.{$type->value}).");
    }

    /** @return list<Prompt> */
    public function all(): array
    {
        $files = glob(resource_path('prompts/*.md')) ?: [];
        sort($files);

        return array_map(fn (string $file) => $this->parse($file), $files);
    }

    private function parse(string $file): Prompt
    {
        $raw = (string) file_get_contents($file);

        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $raw, $m) !== 1) {
            throw new RuntimeException(basename($file).': missing front matter.');
        }

        $meta = [];

        foreach (preg_split('/\R/', trim($m[1])) ?: [] as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $meta[trim($key)] = trim($value);
        }

        if (preg_match('/\A[a-z0-9_.]{1,20}\z/', $meta['version'] ?? '') !== 1 || ($meta['type'] ?? '') === '') {
            throw new RuntimeException(basename($file).': front matter needs version (max 20 chars) and type.');
        }

        if (preg_match('/<!-- system -->\R(.*?)\R<!-- user -->\R(.*)\z/s', $m[2], $parts) !== 1 || ! str_contains($parts[2], '{{context}}')) {
            throw new RuntimeException(basename($file).': needs <!-- system --> and <!-- user --> sections, and {{context}} in the user section.');
        }

        return new Prompt($meta['version'], $meta['type'], trim($parts[1]), trim($parts[2]), hash('sha256', $raw));
    }
}
