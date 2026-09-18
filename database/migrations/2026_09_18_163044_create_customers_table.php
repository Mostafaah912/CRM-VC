<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `customers`. phone_normalized (989XXXXXXXXX) is the identity key.
 * No aggregate counters live here (decision C7) — they belong in
 * customer_metrics and are always rebuildable from orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_normalized', 15)->unique();
            $table->string('phone_raw_last', 25)->nullable();
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('display_name', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('province', 60)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('lifecycle_stage', 20)->default('prospect');
            $table->boolean('metrics_dirty')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active', 'blocked', 'anonymized'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_lifecycle_stage_check CHECK (lifecycle_stage IN ('prospect', 'new', 'active', 'repeat', 'loyal', 'at_risk', 'dormant', 'lost'))");
        DB::statement('CREATE INDEX customers_metrics_dirty_index ON customers (metrics_dirty) WHERE metrics_dirty = true');
        DB::statement('CREATE INDEX customers_display_name_trgm_index ON customers USING gin (display_name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
