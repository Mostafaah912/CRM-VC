<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('has every P1-04 table and stores all of their timestamps as timestamptz', function () {
    $tables = [
        'metric_runs', 'customer_metrics', 'segments', 'segment_members', 'daily_metrics', 'cohort_snapshots',
        'product_affinities', 'customer_category_purchases', 'customer_product_purchases',
        'sync_cursors', 'sync_jobs', 'sync_logs', 'reconciliation_reports', 'integrations',
        'ai_insights', 'ai_usage_daily', 'ai_tool_calls',
    ];

    foreach ($tables as $table) {
        expect(DB::selectOne('select to_regclass(?) as t', ["public.{$table}"])->t)->not->toBeNull("{$table} is missing");
    }

    $naive = DB::select(
        "select table_name, column_name from information_schema.columns
         where table_schema = 'public' and data_type = 'timestamp without time zone' and table_name = any(?)",
        ['{'.implode(',', $tables).'}'],
    );

    expect($naive)->toBeEmpty();
});

it('has no float/real/double money or metric columns anywhere in the new tables', function () {
    $floats = DB::select(
        "select table_name, column_name from information_schema.columns
         where table_schema = 'public' and data_type in ('real', 'double precision')",
    );

    expect($floats)->toBeEmpty();
});
