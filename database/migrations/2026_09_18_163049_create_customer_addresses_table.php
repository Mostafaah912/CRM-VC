<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PRD §09 `customer_addresses`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('province', 60)->nullable();
            $table->string('city', 80)->nullable();
            $table->text('address')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index('customer_id');
        });

        DB::statement("ALTER TABLE customer_addresses ADD CONSTRAINT customer_addresses_type_check CHECK (type IN ('billing', 'shipping'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
