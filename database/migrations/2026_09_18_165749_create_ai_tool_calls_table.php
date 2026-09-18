<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `ai_tool_calls` (042) — audit trail of every read-only tool the model called.
 * `arguments` defaults to `{}` for tools that take none. There is deliberately NO `ai_actions`
 * table in Phase 1 (PRD §19), and migration 043 (the read-only PostgreSQL role) belongs to
 * P7-01 where its INSERT-must-fail test (GATE 4) lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_id')->constrained('ai_insights')->cascadeOnDelete();
            $table->string('tool_name', 60);
            $table->jsonb('arguments')->default(DB::raw("'{}'::jsonb"));
            $table->integer('result_size')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('insight_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};
