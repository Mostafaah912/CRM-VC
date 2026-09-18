<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\SchemaProbe;

function segmentCustomer(): int
{
    return DB::table('customers')->insertGetId([
        'phone_normalized' => '989'.fake()->unique()->numerify('#########'), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function segmentRow(array $overrides = []): int
{
    return DB::table('segments')->insertGetId(array_merge([
        'name' => 'Segment '.fake()->unique()->numerify('####'),
        'type' => 'static',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('has segments exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('segments', [
        'id' => ['bigint', false, null],
        'name' => ['varchar(120)', false, null],
        'description' => ['text', true, null],
        'type' => ['varchar(10)', false, "'dynamic'"],
        'rule' => ['jsonb', true, null],
        'rule_version' => ['smallint', false, '1'],
        'member_count' => ['int', false, '0'],
        'last_evaluated_at' => ['tstz', true, null],
        'last_eval_ms' => ['int', true, null],
        'is_active' => ['bool', false, 'true'],
        'is_system' => ['bool', false, 'false'],
        'created_by' => ['bigint', true, null],
        'created_at' => ['tstz', true, null],
        'updated_at' => ['tstz', true, null],
        'deleted_at' => ['tstz', true, null],
    ]))->toBeEmpty();
});

it('has segment_members exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('segment_members', [
        'segment_id' => ['bigint', false, null],
        'customer_id' => ['bigint', false, null],
        'added_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty()
        ->and(SchemaProbe::primaryKey('segment_members'))->toBe(['segment_id', 'customer_id']);
});

it('defaults a segment to an active, non-system dynamic segment with no members', function () {
    $row = DB::table('segments')->find(segmentRow(['type' => 'dynamic', 'rule' => json_encode(['op' => 'AND', 'children' => []])]));

    expect($row->is_active)->toBeTrue()->and($row->is_system)->toBeFalse()->and($row->member_count)->toBe(0)
        ->and($row->rule_version)->toBe(1)->and($row->deleted_at)->toBeNull();
});

it('rejects a segment type outside dynamic, static, manual', function () {
    segmentRow(['type' => 'smart']);
})->throws(QueryException::class);

it('requires a rule for a dynamic segment but not for static or manual ones', function () {
    segmentRow(['type' => 'static']);
    segmentRow(['type' => 'manual']);

    expect(DB::table('segments')->count())->toBe(2);
});

it('rejects a dynamic segment without a rule', function () {
    segmentRow(['type' => 'dynamic', 'rule' => null]);
})->throws(QueryException::class);

it('stores the rule as JSONB', function () {
    $id = segmentRow(['type' => 'dynamic', 'rule' => json_encode(['op' => 'AND', 'children' => [['field' => 'rfm_segment', 'operator' => '=', 'value' => 'champion']]])]);

    expect(DB::selectOne("select rule->'children'->0->>'field' as f from segments where id = ?", [$id])->f)->toBe('rfm_segment');
});

it('keeps live segment names unique case-insensitively', function () {
    segmentRow(['name' => 'Champions']);

    segmentRow(['name' => 'champions']);
})->throws(QueryException::class);

it('frees a name once its segment is soft deleted', function () {
    $id = segmentRow(['name' => 'Champions']);
    DB::table('segments')->where('id', $id)->update(['deleted_at' => now()]);

    segmentRow(['name' => 'CHAMPIONS']);

    expect(DB::table('segments')->count())->toBe(2);
});

it('declares the unique name index as partial over lower(name) for live rows', function () {
    $definition = collect(SchemaProbe::indexes('segments'))->first(fn ($d) => str_contains($d, 'lower('));

    expect($definition)->toContain('UNIQUE')->toContain('lower((name)::text)')->toContain('WHERE (deleted_at IS NULL)');
});

it('keeps a segment when its creator is deleted', function () {
    expect(SchemaProbe::foreignKey('segments', 'created_by'))->toBe(['ref_table' => 'users', 'delete_rule' => 'SET NULL']);

    $userId = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $id = segmentRow(['created_by' => $userId]);
    DB::table('users')->where('id', $userId)->delete();

    expect(DB::table('segments')->where('id', $id)->value('created_by'))->toBeNull();
});

it('cascades segment membership when the segment or the customer is deleted', function () {
    expect(SchemaProbe::foreignKey('segment_members', 'segment_id'))->toBe(['ref_table' => 'segments', 'delete_rule' => 'CASCADE'])
        ->and(SchemaProbe::foreignKey('segment_members', 'customer_id'))->toBe(['ref_table' => 'customers', 'delete_rule' => 'CASCADE']);

    $segment = segmentRow();
    $a = segmentCustomer();
    $b = segmentCustomer();
    DB::table('segment_members')->insert([['segment_id' => $segment, 'customer_id' => $a], ['segment_id' => $segment, 'customer_id' => $b]]);

    DB::table('customers')->where('id', $a)->delete();
    expect(DB::table('segment_members')->count())->toBe(1);

    DB::table('segments')->where('id', $segment)->delete();
    expect(DB::table('segment_members')->count())->toBe(0);
});

it('lists a customer in a segment at most once', function () {
    $segment = segmentRow();
    $customer = segmentCustomer();
    DB::table('segment_members')->insert(['segment_id' => $segment, 'customer_id' => $customer]);

    DB::table('segment_members')->insert(['segment_id' => $segment, 'customer_id' => $customer]);
})->throws(QueryException::class);

it('can look segments up by customer (index on customer_id)', function () {
    expect(SchemaProbe::hasIndexOn('segment_members', 'customer_id'))->toBeTrue();
});
