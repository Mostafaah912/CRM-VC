<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P8-03g: the catalog sync is resumable. `checkpoint` holds the state of ONE in-flight catalog run (mode, frozen cursor_to,
| modified_after, phase, next page, started_at) while it is running or after it was killed, and is NULL once the run completed.
| It is operational state, not a derived aggregate: losing it only means the next run starts from page 1. The AI read-only
| role is granted explicit columns of sync_cursors, so this column is invisible to it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->jsonb('checkpoint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->dropColumn('checkpoint');
        });
    }
};
