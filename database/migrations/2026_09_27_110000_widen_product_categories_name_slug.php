<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bugfix (Sprint 6, live catalog sync attempt): `product_categories.slug varchar(180)` rejected a real
 * Woo category (id 2346, slug 194 characters — a URL-percent-encoded Persian slug; WordPress
 * percent-encodes non-Latin `sanitize_title()` output rather than transliterating it, which inflates
 * character count far past the visible word length). Transaction rolled back cleanly; nothing was lost.
 *
 * Widened to 255, not just past 194: WordPress's own source-of-truth columns for a term are
 * `wp_terms.name varchar(200)` and `wp_terms.slug varchar(200)` — both already bigger than our 160/180 —
 * so 255 is a deliberate margin above the true upstream limit, project-owner's explicit choice.
 * `name` is widened here too, alongside `slug`, per the same instruction to fix every column with the
 * same class of risk in one pass: it shares the identical upstream 200-char limit and was equally
 * undersized (160), even though no live category has hit it yet — same reasoning, same fix, one migration.
 *
 * Checked and NOT touched, with reasons (schema-and-code only, no further live scan — see
 * docs/architecture/sprint-6.md for why a live products crawl was aborted this task):
 *  - `products.slug` (260): already above the real `wp_posts.post_name` limit (200). Safe as-is.
 *  - `products.name` (250): maps to `wp_posts.post_title`, which is `TEXT` in WordPress — no fixed
 *    upstream column limit exists to compare against, so no evidence-based case to widen it further.
 *  - `products.sku` (80, P6-decision) / `product_variations.sku` (80): a WooCommerce SKU is stored in
 *    postmeta (`longtext`), with no WordPress-side column limit either. Different risk class from a
 *    percent-encoded slug (SKUs are short alphanumeric codes in practice); no live violation was found
 *    or reasoned, so not widened speculatively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('name', 255)->change();
            $table->string('slug', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('name', 160)->change();
            $table->string('slug', 180)->nullable()->change();
        });
    }
};
