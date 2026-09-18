<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §09 `permission_overrides` + §20: explicit deny always wins over an
 * explicit allow, which always wins over a role grant. This table is why —
 * PermissionService (P0-07) reads it before consulting role grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('effect', 5);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['user_id', 'permission_id']);
        });

        DB::statement("ALTER TABLE permission_overrides ADD CONSTRAINT permission_overrides_effect_check CHECK (effect IN ('allow', 'deny'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_overrides');
    }
};
