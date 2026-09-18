<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `integrations` (039). `config` holds the ENCRYPTED Woo/AI credentials as text
 * (CLAUDE.md §6) — never plaintext, never logged. `last_health_status` has no CHECK: the PRD
 * gives no value set and its only consumer (HealthCheckJob) is Sprint 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('provider', 40);
            $table->text('config');
            $table->boolean('is_active');
            $table->timestampTz('last_health_check_at')->nullable();
            $table->string('last_health_status', 15)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
