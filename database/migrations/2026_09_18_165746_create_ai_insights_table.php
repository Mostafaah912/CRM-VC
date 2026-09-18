<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `ai_insights` (040). Cost is numeric(10,5) USD, never a float. `period_start/end`
 * are dates (PRD gives no type; insights cover whole days, like reconciliation and ai_usage_daily).
 * Token counts default to 0 like cost_usd. Insights survive their requesting user (SET NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('scope', 40)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->jsonb('payload');
            $table->string('provider', 20);
            $table->string('model', 80);
            $table->string('prompt_version', 20);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->integer('duration_ms')->nullable();
            $table->string('cache_key', 120)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE ai_insights ADD CONSTRAINT ai_insights_type_check CHECK (type IN ('daily_brief', 'weekly_review', 'explain', 'anomaly', 'customer'))");
        DB::statement('CREATE UNIQUE INDEX ai_insights_cache_key_unique ON ai_insights (cache_key) WHERE cache_key IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
