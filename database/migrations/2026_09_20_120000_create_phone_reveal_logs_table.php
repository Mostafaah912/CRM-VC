<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-02: who revealed which customer's full phone, when, and from where — one row per reveal, written by PhoneRevealService in
 * the same transaction that releases the number. `revealed_by` is RESTRICT: the trail must outlive (and so block the deletion
 * of) the user it names. `customer_id` is CASCADE: the rows go with a customer that is really deleted (soft-deleting keeps them).
 * `ip` is nullable (a request can have none); `user_agent` is cut to 500 characters by the service and never null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_reveal_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('revealed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revealed_at')->default(DB::raw('now()'));
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->default('');
        });

        DB::statement('CREATE INDEX phone_reveal_logs_customer_revealed_at_index ON phone_reveal_logs (customer_id, revealed_at DESC)');
        DB::statement('CREATE INDEX phone_reveal_logs_revealed_by_revealed_at_index ON phone_reveal_logs (revealed_by, revealed_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_reveal_logs');
    }
};
