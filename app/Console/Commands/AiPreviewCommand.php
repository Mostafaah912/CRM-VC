<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Analyst\AnalystRequestBuilder;
use App\Modules\Ai\Analyst\OutputSchema;
use App\Modules\Ai\Context\ContextBuilder;
use App\Modules\Ai\Enums\AiInsightType;
use Illuminate\Console\Command;

/**
 * `hm:ai-preview {type}` (P7-07): builds the REAL context from the dev database (read-only, through the ai_readonly role) and prints
 * the complete request that would go to the provider — system prompt, user message, output schema, sizes and an approximate token
 * count. It never calls a provider and needs no API key; it cannot spend anything. The command holds no logic of its own.
 */
final class AiPreviewCommand extends Command
{
    protected $signature = 'hm:ai-preview {type=daily_brief : daily_brief or weekly_review} {--no-data : print the context sections as a size table only, not the JSON itself}';

    protected $description = 'Show the full AI request (system + user + schema + size) for the real data, without calling any provider';

    public function handle(ContextBuilder $contexts, AnalystRequestBuilder $requests): int
    {
        $type = AiInsightType::tryFrom((string) $this->argument('type'));

        if ($type === null || ! in_array($type, [AiInsightType::DailyBrief, AiInsightType::WeeklyReview], true)) {
            $this->error('type must be daily_brief or weekly_review.');

            return self::FAILURE;
        }

        $context = $contexts->build($type);
        $analyst = $requests->build($type, $context);
        $quality = (array) (json_decode($context->json, true)['data_quality'] ?? []);

        $this->line("PROMPT   {$analyst->promptVersion}  ({$type->value})");
        $this->line('MODEL    '.((string) config('ai.model') !== '' ? config('ai.model') : '(AI_MODEL not set — fine for a preview)'));
        $this->line(sprintf('CONTEXT  %s / %s bytes | truncated: %s | redactions: %d', number_format($context->sizeBytes), number_format($context->maxBytes), $context->truncated ? 'yes' : 'no', $context->redactions));
        $this->line(sprintf('FRESH    as_of %s | data_age_days %s | stale_data: %s | warnings: %s', $quality['as_of']['iso'] ?? 'n/a', $quality['data_age_days'] ?? 'n/a', ($quality['stale_data'] ?? false) ? 'yes' : 'no', implode(',', (array) ($quality['warnings'] ?? [])) ?: 'none'));
        $this->line(sprintf('REQUEST  system %s bytes + user %s bytes | ~%s input tokens | maxOutputTokens %d | temperature %s', number_format($analyst->systemBytes), number_format($analyst->userBytes), number_format($analyst->estimatedInputTokens), $analyst->request->maxOutputTokens, $analyst->request->temperature));
        $this->newLine();
        $this->line('SECTIONS (bytes)');

        foreach ($context->sectionBytes as $section => $bytes) {
            $this->line(sprintf('  %-18s %s', $section, number_format($bytes)));
        }

        $this->newLine();
        $this->line('=== SYSTEM ===');
        $this->line($analyst->request->systemPrompt);
        $this->newLine();
        $this->line('=== USER ===');
        $this->line($this->option('no-data') ? str_replace(str_replace('<', AnalystRequestBuilder::LT, $context->json), '[context JSON omitted: --no-data]', $analyst->request->messages[0]->content) : $analyst->request->messages[0]->content);
        $this->newLine();
        $this->line('=== OUTPUT SCHEMA ===');
        $this->line((string) json_encode(OutputSchema::schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
