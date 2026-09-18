<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `sync_logs` (037). The PRD gives no level list; the eight standard PSR-3/Monolog levels
 * are allowed so any Laravel `Log` level maps without a later migration. Logs die with their job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_job_id')->constrained('sync_jobs')->cascadeOnDelete();
            $table->string('level', 10);
            $table->string('message', 500);
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('sync_job_id');
        });

        DB::statement("ALTER TABLE sync_logs ADD CONSTRAINT sync_logs_level_check CHECK (level IN ('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
