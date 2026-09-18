<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §08: a name mismatch on a shared phone is queued here for a human — the
 * customer is never split and the order is never dropped. woo_order_id is a
 * plain Woo id, not an FK (orders arrive in P1-03, and the conflict must
 * survive regardless).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('existing_name', 160)->nullable();
            $table->string('incoming_name', 160)->nullable();
            $table->bigInteger('woo_order_id')->nullable();
            $table->string('reason', 120);
            $table->string('status', 15)->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('customer_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE identity_conflicts ADD CONSTRAINT identity_conflicts_status_check CHECK (status IN ('pending', 'confirmed_same', 'confirmed_different', 'ignored'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_conflicts');
    }
};
