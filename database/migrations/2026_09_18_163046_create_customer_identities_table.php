<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PRD §08/§09: maps a Woo user id or guest order to the one customer that owns the phone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('source', 30);
            $table->string('source_id', 64);
            $table->string('confidence', 10)->default('high');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['source', 'source_id']);
            $table->index('customer_id');
        });

        DB::statement("ALTER TABLE customer_identities ADD CONSTRAINT customer_identities_source_check CHECK (source IN ('woo_user', 'woo_guest_order'))");
        DB::statement("ALTER TABLE customer_identities ADD CONSTRAINT customer_identities_confidence_check CHECK (confidence IN ('high', 'medium', 'low'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
    }
};
