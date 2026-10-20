<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Illuminate\Support\Facades\File;

/**
 * Debug aid behind `hm:ai-generate --debug-raw` (OFF by default; one instance per process). When armed, HttpAiDriver hands it the raw HTTP
 * body of every provider answer BEFORE it is parsed, so a cut-off or malformed answer can still be inspected. Written to
 * storage/app/tmp (git-ignored) as-is: the provider's response only — never the prompt, the context or the API key. The files are
 * temporary by intent; whoever armed it deletes them after looking.
 */
final class RawResponseCapture
{
    /** @var list<string> */
    private array $files = [];

    private bool $armed = false;

    public function arm(): void
    {
        $this->armed = true;
    }

    public function armed(): bool
    {
        return $this->armed;
    }

    public function store(string $provider, string $body): void
    {
        if (! $this->armed) {
            return;
        }

        $dir = storage_path('app/tmp');
        File::ensureDirectoryExists($dir);
        $path = sprintf('%s/ai-raw-%s-%d-%d.json', $dir, preg_replace('/[^a-z0-9]/', '', strtolower($provider)), time(), count($this->files) + 1);
        File::put($path, $body);
        $this->files[] = $path;
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->files;
    }
}
