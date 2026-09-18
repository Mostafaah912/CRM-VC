<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `segment_members` (029) — derived from segments.rule; rebuilt on every evaluation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segment_members', function (Blueprint $table) {
            $table->foreignId('segment_id')->constrained('segments')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->timestampTz('added_at')->useCurrent();

            $table->primary(['segment_id', 'customer_id']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_members');
    }
};
