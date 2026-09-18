<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\SchemaProbe;

function insightRow(array $overrides = []): int
{
    return DB::table('ai_insights')->insertGetId(array_merge([
        'type' => 'daily_brief', 'payload' => json_encode(['headline' => 'x']),
        'provider' => 'anthropic', 'model' => 'm', 'prompt_version' => 'analyst.daily.v1',
    ], $overrides));
}

it('has ai_insights exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('ai_insights', [
        'id' => ['bigint', false, null],
        'type' => ['varchar(30)', false, null],
        'scope' => ['varchar(40)', true, null],
        'period_start' => ['date', true, null],
        'period_end' => ['date', true, null],
        'payload' => ['jsonb', false, null],
        'provider' => ['varchar(20)', false, null],
        'model' => ['varchar(80)', false, null],
        'prompt_version' => ['varchar(20)', false, null],
        'input_tokens' => ['int', false, '0'],
        'output_tokens' => ['int', false, '0'],
        'cost_usd' => ['numeric(10,5)', false, '0'],
        'duration_ms' => ['int', true, null],
        'cache_key' => ['varchar(120)', true, null],
        'requested_by' => ['bigint', true, null],
        'created_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty();
});

it('has ai_usage_daily exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('ai_usage_daily', [
        'date' => ['date', false, null],
        'requests' => ['int', false, '0'],
        'input_tokens' => ['bigint', false, '0'],
        'output_tokens' => ['bigint', false, '0'],
        'cost_usd' => ['numeric(10,5)', false, '0'],
    ]))->toBeEmpty()
        ->and(SchemaProbe::primaryKey('ai_usage_daily'))->toBe(['date']);
});

it('has ai_tool_calls exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('ai_tool_calls', [
        'id' => ['bigint', false, null],
        'insight_id' => ['bigint', false, null],
        'tool_name' => ['varchar(60)', false, null],
        'arguments' => ['jsonb', false, "'{}'"],
        'result_size' => ['int', true, null],
        'duration_ms' => ['int', true, null],
        'error' => ['text', true, null],
        'created_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty();
});

it('accepts the five insight types in the PRD and rejects others', function () {
    foreach (['daily_brief', 'weekly_review', 'explain', 'anomaly', 'customer'] as $type) {
        insightRow(['type' => $type]);
    }

    expect(DB::table('ai_insights')->count())->toBe(5);
});

it('rejects an unknown insight type', function () {
    insightRow(['type' => 'campaign']);
})->throws(QueryException::class);

it('defaults token counts and cost to zero and stores the payload as JSONB', function () {
    $id = insightRow(['payload' => json_encode(['insights' => [['text' => 'x', 'kind' => 'fact']]])]);
    $row = DB::table('ai_insights')->find($id);

    expect($row->input_tokens)->toBe(0)->and($row->output_tokens)->toBe(0)->and($row->cost_usd)->toBe('0.00000')
        ->and(DB::selectOne("select payload->'insights'->0->>'kind' as k from ai_insights where id = ?", [$id])->k)->toBe('fact');
});

it('stores cost in USD with five decimals, never a float', function () {
    $id = insightRow(['cost_usd' => 0.0123456]);

    expect(DB::table('ai_insights')->where('id', $id)->value('cost_usd'))->toBe('0.01235');
});

it('uses one cached insight per cache_key but allows unlimited uncached insights', function () {
    insightRow();
    insightRow();
    insightRow(['cache_key' => 'daily:2026-09-18']);

    expect(DB::table('ai_insights')->count())->toBe(3);

    insightRow(['cache_key' => 'daily:2026-09-18']);
})->throws(QueryException::class);

it('declares the cache_key unique index as partial', function () {
    $definition = collect(SchemaProbe::indexes('ai_insights'))->first(fn ($d) => str_contains($d, 'cache_key'));

    expect($definition)->toContain('UNIQUE')->toContain('WHERE (cache_key IS NOT NULL)');
});

it('keeps an insight when the requesting user is deleted', function () {
    expect(SchemaProbe::foreignKey('ai_insights', 'requested_by'))->toBe(['ref_table' => 'users', 'delete_rule' => 'SET NULL']);

    $userId = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $id = insightRow(['requested_by' => $userId]);
    DB::table('users')->where('id', $userId)->delete();

    expect(DB::table('ai_insights')->where('id', $id)->value('requested_by'))->toBeNull();
});

it('deletes tool calls with their insight and defaults arguments to an empty object', function () {
    expect(SchemaProbe::foreignKey('ai_tool_calls', 'insight_id'))->toBe(['ref_table' => 'ai_insights', 'delete_rule' => 'CASCADE']);

    $id = insightRow();
    DB::table('ai_tool_calls')->insert(['insight_id' => $id, 'tool_name' => 'get_dashboard_metrics']);

    expect(DB::selectOne('select arguments::text as a from ai_tool_calls')->a)->toBe('{}');

    DB::table('ai_insights')->where('id', $id)->delete();
    expect(DB::table('ai_tool_calls')->count())->toBe(0);
});

it('requires a real insight for every tool call', function () {
    DB::table('ai_tool_calls')->insert(['insight_id' => 999_999, 'tool_name' => 't']);
})->throws(QueryException::class);

it('tracks one usage row per day with bigint token counts', function () {
    DB::table('ai_usage_daily')->insert(['date' => '2026-09-18', 'requests' => 3, 'input_tokens' => 5_000_000_000, 'output_tokens' => 1, 'cost_usd' => 1.5]);

    expect(DB::table('ai_usage_daily')->value('input_tokens'))->toBe(5_000_000_000);

    DB::table('ai_usage_daily')->insert(['date' => '2026-09-18']);
})->throws(QueryException::class);

it('does not create ai_actions in Phase 1 (PRD §19)', function () {
    expect(SchemaProbe::exists('ai_actions'))->toBeFalse();
});
