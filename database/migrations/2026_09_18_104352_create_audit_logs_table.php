<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PRD §09 `audit_logs`. Written only through AuditService::record() (P0-08), never inline. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 10)->default('user');
            $table->string('action', 60);
            $table->string('auditable_type', 120);
            $table->bigInteger('auditable_id');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('source', 40)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'system', 'ai'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
