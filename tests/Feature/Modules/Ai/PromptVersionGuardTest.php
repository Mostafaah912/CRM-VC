<?php

declare(strict_types=1);

use App\Modules\Ai\Analyst\OutputSchema;
use App\Modules\Ai\Analyst\PromptRepository;
use App\Modules\Ai\Enums\AiInsightType;

/*
| .claude/rules/ai.md: "Changing a prompt bumps its version." tests/fixtures/ai_prompt_lock.json records, per version, the sha256 of the
| prompt file and of the output schema + limits. Edit a prompt or the schema without a new version and these tests fail; bump the
| version and the lock must get the new entry in the same change — a deliberate, reviewable step (never regenerate it blindly).
*/

function promptLock(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/ai_prompt_lock.json')), true);
}

it('fails when a prompt file is edited without a new version', function () {
    foreach (app(PromptRepository::class)->all() as $prompt) {
        expect(array_key_exists($prompt->version, promptLock()))->toBeTrue("{$prompt->version} has no entry in tests/fixtures/ai_prompt_lock.json — a new version needs one");
        expect(promptLock()[$prompt->version]['prompt_sha256'])->toBe($prompt->sha256, "prompt {$prompt->version} was edited: bump its version (and add the new lock entry)");
    }
});

it('fails when the output schema or its limits change without a new prompt version', function () {
    $schema = hash('sha256', json_encode(OutputSchema::schema()).json_encode(OutputSchema::limits()));

    // Only the versions IN USE are bound to the current schema; superseded versions keep the hash of the schema they shipped with.
    foreach (['daily_brief', 'weekly_review'] as $type) {
        $version = config("ai.prompts.{$type}");

        expect(promptLock()[$version]['schema_sha256'])->toBe($schema, "the output schema changed under {$version}: bump the prompt versions");
    }
});

it('keeps versions well-formed: unique, <= 20 characters (ai_insights.prompt_version), named after their file, exactly one configured default per type that exists on disk', function () {
    $prompts = app(PromptRepository::class)->all();
    $versions = array_map(fn ($p) => $p->version, $prompts);
    $types = array_map(fn ($p) => $p->type, $prompts);

    expect($versions)->toBe(array_values(array_unique($versions)))->and(array_values(array_unique($types)))->toEqualCanonicalizing(['daily_brief', 'weekly_review']);

    foreach (['daily_brief', 'weekly_review'] as $type) {
        $default = config("ai.prompts.{$type}");

        expect(array_values(array_filter($prompts, fn ($p) => $p->type === $type && $p->version === $default)))->toHaveCount(1, "ai.prompts.{$type} must name exactly one prompt file of that type");
    }

    foreach ($prompts as $p) {
        expect(strlen($p->version))->toBeLessThanOrEqual(20)->and(file_exists(resource_path("prompts/{$p->version}.md")))->toBeTrue();
    }
});

it('detects a tampered prompt (self-test of the guard)', function () {
    $file = resource_path('prompts/analyst.daily.v1.md');
    $original = (string) file_get_contents($file);

    try {
        file_put_contents($file, $original."\nاین خط بدون افزایش نسخه اضافه شده است.\n");
        $edited = collect(app(PromptRepository::class)->all())->firstWhere('version', 'analyst.daily.v1');

        expect($edited->sha256)->not->toBe(promptLock()['analyst.daily.v1']['prompt_sha256']);
    } finally {
        file_put_contents($file, $original);
    }

    expect(collect(app(PromptRepository::class)->all())->firstWhere('version', 'analyst.daily.v1')->sha256)->toBe(promptLock()['analyst.daily.v1']['prompt_sha256']);
});

it('keeps the superseded v1 prompts on disk, byte-for-byte as locked', function () {
    foreach (['analyst.daily.v1', 'analyst.weekly.v1'] as $version) {
        expect(file_exists(resource_path("prompts/{$version}.md")))->toBeTrue()
            ->and(collect(app(PromptRepository::class)->all())->firstWhere('version', $version)->sha256)->toBe(promptLock()[$version]['prompt_sha256']);
    }
});

it('makes v3 the default and the v3 prompts carry the new rules', function () {
    $repo = app(PromptRepository::class);

    expect($repo->for(AiInsightType::DailyBrief)->version)->toBe('analyst.daily.v3')
        ->and($repo->for(AiInsightType::WeeklyReview)->version)->toBe('analyst.weekly.v3');

    foreach (['analyst.daily.v3', 'analyst.weekly.v3'] as $v) {
        $text = file_get_contents(resource_path("prompts/{$v}.md"));

        expect($text)->toContain('one_time_customers_percent')->toContain('product_coverage_low')->toContain('value_at_risk_clv_confidence')
            ->toContain('support')->toContain('period_anchor_mismatch')->toContain('حداکثر medium');
    }
});

it('keeps v2 on disk, byte-for-byte as locked', function () {
    foreach (['analyst.daily.v2', 'analyst.weekly.v2'] as $version) {
        expect(collect(app(PromptRepository::class)->all())->firstWhere('version', $version)->sha256)->toBe(promptLock()[$version]['prompt_sha256']);
    }
});
