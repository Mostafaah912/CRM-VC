<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\SchemaProbe;

function syncJobRow(array $overrides = []): int
{
    return DB::table('sync_jobs')->insertGetId(array_merge(['entity' => 'orders', 'mode' => 'incremental'], $overrides));
}

it('has sync_cursors exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('sync_cursors', [
        'entity' => ['varchar(20)', false, null],
        'cursor_value' => ['tstz', true, null],
        'last_run_at' => ['tstz', true, null],
        'last_status' => ['varchar(15)', true, null],
        'consecutive_failures' => ['int', false, '0'],
        'updated_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty()
        ->and(SchemaProbe::primaryKey('sync_cursors'))->toBe(['entity']);
});

it('has sync_jobs exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('sync_jobs', [
        'id' => ['bigint', false, null],
        'entity' => ['varchar(20)', false, null],
        'mode' => ['varchar(12)', false, null],
        'status' => ['varchar(15)', false, "'running'"],
        'cursor_from' => ['tstz', true, null],
        'cursor_to' => ['tstz', true, null],
        'pages_processed' => ['int', false, '0'],
        'records_processed' => ['int', false, '0'],
        'records_failed' => ['int', false, '0'],
        'started_at' => ['tstz', false, 'current_timestamp'],
        'finished_at' => ['tstz', true, null],
        'error' => ['text', true, null],
    ]))->toBeEmpty();
});

it('has sync_logs exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('sync_logs', [
        'id' => ['bigint', false, null],
        'sync_job_id' => ['bigint', false, null],
        'level' => ['varchar(10)', false, null],
        'message' => ['varchar(500)', false, null],
        'context' => ['jsonb', true, null],
        'created_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty();
});

it('has reconciliation_reports exactly as the PRD defines it', function () {
    expect(SchemaProbe::mismatches('reconciliation_reports', [
        'id' => ['bigint', false, null],
        'period_start' => ['date', false, null],
        'period_end' => ['date', false, null],
        'woo_orders' => ['int', false, null],
        'crm_orders' => ['int', false, null],
        'woo_revenue' => ['bigint', false, null],
        'crm_revenue' => ['bigint', false, null],
        'orders_diff' => ['int', false, null],
        'revenue_diff' => ['bigint', false, null],
        'diff_percent' => ['numeric(7,4)', false, null],
        'is_acceptable' => ['bool', false, null],
        'details' => ['jsonb', true, null],
        'created_at' => ['tstz', false, 'current_timestamp'],
    ]))->toBeEmpty();
});

it('has integrations exactly as the PRD defines it (config is encrypted text)', function () {
    expect(SchemaProbe::mismatches('integrations', [
        'id' => ['bigint', false, null],
        'key' => ['varchar(40)', false, null],
        'provider' => ['varchar(40)', false, null],
        'config' => ['text', false, null],
        'is_active' => ['bool', false, null],
        'last_health_check_at' => ['tstz', true, null],
        'last_health_status' => ['varchar(15)', true, null],
        'created_at' => ['tstz', true, null],
        'updated_at' => ['tstz', true, null],
    ]))->toBeEmpty();
});

it('keeps one cursor per entity and starts with no failures', function () {
    DB::table('sync_cursors')->insert(['entity' => 'orders']);

    expect(DB::table('sync_cursors')->first())->toMatchObject(['entity' => 'orders', 'cursor_value' => null, 'consecutive_failures' => 0]);

    DB::table('sync_cursors')->insert(['entity' => 'orders']);
})->throws(QueryException::class);

it('accepts only the sync job statuses for a cursor\'s last_status', function () {
    foreach (['running', 'completed', 'failed', 'partial'] as $i => $status) {
        DB::table('sync_cursors')->insert(['entity' => "e{$i}", 'last_status' => $status]);
    }

    expect(DB::table('sync_cursors')->count())->toBe(4);
});

it('rejects an unknown cursor last_status', function () {
    DB::table('sync_cursors')->insert(['entity' => 'orders', 'last_status' => 'stuck']);
})->throws(QueryException::class);

it('defaults a sync job to running with zeroed counters and an open finish time', function () {
    $row = DB::table('sync_jobs')->find(syncJobRow());

    expect($row->status)->toBe('running')->and($row->pages_processed)->toBe(0)->and($row->records_processed)->toBe(0)
        ->and($row->records_failed)->toBe(0)->and($row->finished_at)->toBeNull()->and($row->started_at)->not->toBeNull();
});

it('accepts every sync mode and status in the PRD', function () {
    foreach (['full', 'incremental', 'webhook'] as $mode) {
        foreach (['running', 'completed', 'failed', 'partial'] as $status) {
            syncJobRow(['mode' => $mode, 'status' => $status]);
        }
    }

    expect(DB::table('sync_jobs')->count())->toBe(12);
});

it('rejects an unknown sync mode or status', function (array $row) {
    syncJobRow($row);
})->with([
    'mode' => [['mode' => 'nightly']],
    'status' => [['status' => 'queued']],
])->throws(QueryException::class);

it('freezes the cursor window with timestamptz cursor_from / cursor_to', function () {
    $id = syncJobRow(['cursor_from' => '2026-01-01 00:00:00+00', 'cursor_to' => '2026-01-01 00:15:00+00']);

    expect(DB::selectOne('select extract(epoch from cursor_to - cursor_from) as s from sync_jobs where id = ?', [$id])->s)->toEqual(900);
});

it('deletes a job\'s logs with the job and keeps context as JSONB', function () {
    expect(SchemaProbe::foreignKey('sync_logs', 'sync_job_id'))->toBe(['ref_table' => 'sync_jobs', 'delete_rule' => 'CASCADE']);

    $job = syncJobRow();
    DB::table('sync_logs')->insert(['sync_job_id' => $job, 'level' => 'warning', 'message' => 'unresolvable product', 'context' => json_encode(['woo_order_id' => 9])]);

    expect(DB::selectOne("select context->>'woo_order_id' as id from sync_logs")->id)->toBe('9');

    DB::table('sync_jobs')->where('id', $job)->delete();
    expect(DB::table('sync_logs')->count())->toBe(0);
});

it('requires a real sync job for every log line', function () {
    DB::table('sync_logs')->insert(['sync_job_id' => 999_999, 'level' => 'info', 'message' => 'x']);
})->throws(QueryException::class);

it('accepts standard log levels and rejects anything else', function () {
    $job = syncJobRow();

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        DB::table('sync_logs')->insert(['sync_job_id' => $job, 'level' => $level, 'message' => $level]);
    }

    expect(DB::table('sync_logs')->count())->toBe(8);
});

it('rejects an unknown sync log level', function () {
    DB::table('sync_logs')->insert(['sync_job_id' => syncJobRow(), 'level' => 'loud', 'message' => 'x']);
})->throws(QueryException::class);

it('caps a log message at 500 characters', function () {
    DB::table('sync_logs')->insert(['sync_job_id' => syncJobRow(), 'level' => 'info', 'message' => str_repeat('x', 501)]);
})->throws(QueryException::class);

it('stores a reconciliation report with bigint revenue and a four-decimal variance', function () {
    DB::table('reconciliation_reports')->insert([
        'period_start' => '2024-09-22', 'period_end' => '2024-10-21', 'woo_orders' => 1800, 'crm_orders' => 1800,
        'woo_revenue' => 2_000_000_000, 'crm_revenue' => 1_999_000_000, 'orders_diff' => 0, 'revenue_diff' => 1_000_000,
        'diff_percent' => 0.05, 'is_acceptable' => true, 'details' => json_encode(['month' => '1403-07']),
    ]);
    $row = DB::table('reconciliation_reports')->first();

    expect($row->woo_revenue)->toBe(2_000_000_000)->and($row->diff_percent)->toBe('0.0500')->and($row->is_acceptable)->toBeTrue();
});

it('keeps one integration per key and stores its config as text', function () {
    DB::table('integrations')->insert(['key' => 'woocommerce', 'provider' => 'woocommerce', 'config' => 'eyJpdiI6Ii4uLiJ9', 'is_active' => true]);

    expect(SchemaProbe::hasUniqueOn('integrations', 'key'))->toBeTrue();

    DB::table('integrations')->insert(['key' => 'woocommerce', 'provider' => 'woocommerce', 'config' => 'x', 'is_active' => false]);
})->throws(QueryException::class);
