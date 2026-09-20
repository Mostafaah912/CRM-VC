<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P3-05: notes on Customer 360. `customer_notes` already exists (P1-01, PRD §09) and was never written, so this ALTERS it — a
 * published migration is never edited. The author column keeps its PRD name, `user_id`; the API calls it `author_id`.
 *
 *  - user_id NOT NULL and RESTRICT (was nullable, SET NULL): a note always has an author, and the author cannot be deleted from
 *    under it — the same rule as the phone-reveal trail. Fails loudly if a NULL-author row exists (none did: the table was empty).
 *  - created_at NOT NULL DEFAULT now(): the notes list is a cursor over (created_at, id), and a NULL would break that comparison.
 *  - body CHECK: 1..2000 characters after trimming ASCII whitespace (space, tab, CR, LF, FF, VT), the same limit the request
 *    validates, so no writer can go around it.
 *  - Index (customer_id, created_at DESC, id DESC) replaces the single-column (customer_id) one: it serves the list's one query
 *    and the cursor's tie-break, and its prefix does everything the old index did.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_notes ALTER COLUMN user_id SET NOT NULL');
        DB::statement('ALTER TABLE customer_notes DROP CONSTRAINT customer_notes_user_id_foreign');
        DB::statement('ALTER TABLE customer_notes ADD CONSTRAINT customer_notes_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE customer_notes ALTER COLUMN created_at SET DEFAULT now()');
        DB::statement('ALTER TABLE customer_notes ALTER COLUMN created_at SET NOT NULL');

        DB::statement("ALTER TABLE customer_notes ADD CONSTRAINT customer_notes_body_check CHECK (char_length(btrim(body, ' ' || chr(9) || chr(10) || chr(11) || chr(12) || chr(13))) BETWEEN 1 AND 2000)");

        DB::statement('DROP INDEX customer_notes_customer_id_index');
        DB::statement('CREATE INDEX customer_notes_customer_created_at_id_index ON customer_notes (customer_id, created_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX customer_notes_customer_created_at_id_index');
        DB::statement('CREATE INDEX customer_notes_customer_id_index ON customer_notes (customer_id)');

        DB::statement('ALTER TABLE customer_notes DROP CONSTRAINT customer_notes_body_check');

        DB::statement('ALTER TABLE customer_notes ALTER COLUMN created_at DROP NOT NULL');
        DB::statement('ALTER TABLE customer_notes ALTER COLUMN created_at DROP DEFAULT');

        DB::statement('ALTER TABLE customer_notes DROP CONSTRAINT customer_notes_user_id_foreign');
        DB::statement('ALTER TABLE customer_notes ADD CONSTRAINT customer_notes_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE customer_notes ALTER COLUMN user_id DROP NOT NULL');
    }
};
