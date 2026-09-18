<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `segments` (028). `created_by` is nullable (SET NULL) so system segments seeded in
 * Sprint 5 need no author and a deleted user never deletes a segment. Names are unique
 * case-insensitively among live (not soft-deleted) segments; a dynamic segment must carry a rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('type', 10)->default('dynamic');
            $table->jsonb('rule')->nullable();
            $table->smallInteger('rule_version')->default(1);
            $table->integer('member_count')->default(0);
            $table->timestampTz('last_evaluated_at')->nullable();
            $table->integer('last_eval_ms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE segments ADD CONSTRAINT segments_type_check CHECK (type IN ('dynamic', 'static', 'manual'))");
        DB::statement("ALTER TABLE segments ADD CONSTRAINT segments_dynamic_rule_check CHECK ((type = 'dynamic' AND rule IS NOT NULL) OR type <> 'dynamic')");
        DB::statement('CREATE UNIQUE INDEX segments_name_unique ON segments (lower(name)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
