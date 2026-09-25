# HeyMode Customer OS — Architecture Log (Index)

Shared memory of the project: decisions made, gates passed, open risks. Full task-by-task
history lives in `docs/architecture/sprint-N.md`, split from this file (chore/claude-context,
2026-09-25) — this file is now an index only. **New-task logging convention (CLAUDE.md §12
References): write the full entry for a new task in `docs/architecture/sprint-N.md`; add here
only a 1-3 line index row (and update Decisions/Open Items below if the task changes either).**
Note: this differs from the plain "paragraph in ARCHITECTURE.md" wording in PRD.md's Definition
of Done — PRD.md was left unedited (source of truth); see this chore's final report for the
conflict.

## Environment (real installed versions, recorded at project start — see `docs/architecture/sprint-0.md` for the full P0-00 findings)

- macOS 12.7.6, Intel x86_64. PHP 8.4 via Laravel Herd. Node 22.17.0 / npm 10.9.2.
- **PostgreSQL 15.19** — pinned; VPS must also run 15, not 16 (NTILE/percentile_cont/JSONB/generate_series all verified on 15).
- Redis 7.4.6. Tests: Pest. Deployment: VPS in Sprint 8 (not yet started); local dev runs `php artisan horizon` manually.

## Confirmed decisions

- **Currency: Toman (IRT), int, no decimals** (bigint). Woo API already returns Toman — verified against a real order amount. Never floats for money (CLAUDE.md §2).
- **`realized_statuses = ['processing', 'completed']`** — `shipped` does not exist in this store's data; never hardcode elsewhere, always `config('woo.realized_statuses')`.
- **Guest checkout: disabled store-wide.** Every order has a `customer_id`; the `woo_guest_order` path is dead code.
- **Phone: 100% present** on orders (0% guest, 0% phone-less in the P0-00 sample) — identity keys off phone with no fallback. (Sync later surfaced a small real phone-less rate — see Open Items.)
- **Refunds carry `line_items`** — refund is computed at line-item level, not order level.
- **Email: ~0.3% coverage** — never used even as a secondary identity signal.
- **DB connection timezone: `UTC`, hardcoded** in `config/database.php`'s `pgsql` connection (not `.env` — an architectural constant, not an environment setting). Local Postgres defaults to `Asia/Tehran`; without this, every `timestamptz` write silently shifted 3.5 hours (found in P0-05, see `docs/architecture/sprint-0.md`). `to_jalali*()` PL/pgSQL functions (P0-04) are unaffected — they use explicit `AT TIME ZONE 'Asia/Tehran'`.
- **Permissions: deny > allow (override) > role > default-deny**, enforced in both middleware and service layer (P0-07). This is why Spatie was rejected — no first-class deny override.
- **Segments module: raw SQL banned outright** (arch-test enforced); `app/` broadly bans raw SQL except the Metrics/Analytics modules and one documented, named استثنای Rule 7 (Rule 7 exception) in Catalog's `ProductListService` (P3-07: a store-wide grouped SUM/COUNT/MAX with no query-builder form) — see `docs/architecture/sprint-3.md` for the full reasoning; this line is what `tests/Arch/CatalogBoundaryTest.php` checks for.

## Gate status

- **GATE 0 (starter-kit RTL/Postgres/permissions sanity): PASSED** — end of Sprint 0. Details: `docs/architecture/sprint-0.md`.
- **GATE 1 (Sync reconciliation, zero order-count diff / <1% revenue variance, every month since 1403-07): PASSED — 1405/06/29 (2026-09-20)**, 23/23 months green, largest diff 0.0000%. Two real blockers found and fixed en route (P2-13 phone-less orders, P2-14 fractional `unit_price`). Operational risk found and still open: the nightly `hm:reconcile --all` monthly job times out and kills its Worker on large months — see Open Items. Details: `docs/architecture/sprint-2.md`.
- **GATE 2 (metrics match `expected_metrics.json` exactly): PASSED** — end of Sprint 4 (P4-08), verified by running the _real_ `RecomputeMetricsJob` pipeline (not just independent oracles) against the fixture. Three real pre-existing bugs found and fixed this way (`clv_confidence` logic, `churn_risk_score` rounding, missing `median/avg_days_between` writes) — the fixture itself was never touched. Details: `docs/architecture/sprint-4.md`.
- **GATE 3 (SQL-injection test on RuleCompiler): PASSED** — on `sprint/5-segmentation` (P5-03), not yet merged to `main`. Details: `docs/architecture/sprint-5.md`.
- **GATE 4 (AI connection has no write access):** not yet reached — the AI module hasn't started (Sprint 6+).

## Open items and known risks

- ⚠ **`identity_conflicts.status` is `varchar(15)` but `confirmed_different` is 19 characters** — writing it fails with "value too long." Published migration not edited (per CLAUDE.md §3); needs a new migration widening to ≥`varchar(20)` before any conflict-review flow can confirm-as-different. `docs/architecture/sprint-2.md` (P1-01 finding, restated at P2-12).
- ⚠ **Nightly `hm:reconcile --all` will time out and kill its Worker** on large months unless the reconcile queue's Horizon supervisor timeout is raised to ≥300s (job's own `$timeout` was raised to 300, `retry_after` to 330; Horizon's own `config/horizon.php` supervisor value was never verified live). Must be resolved before enabling the production scheduler. `docs/architecture/sprint-2.md` (P2-15, labeled "P3-01" in the original log — see its own note).
- ⚠ **Simple products have no resolvable SKU/price** — `products` has no `sku`/`price` column, and PRD doesn't define a "default variation" for a simple product, so simple-product order lines are stored with `product_id/variation_id = NULL` (revenue is never lost, only Affinity/product-level reporting coverage is). Needs an explicit decision (a synthetic default variation?) before Catalog/Affinity work relies on simple-product SKUs. `docs/architecture/sprint-2.md` (P2-05/P2-06).
- ⚠ **`woo.webhook_allowed_ips` is empty** (IP check effectively off; HMAC signature check is still always enforced) — needs the real store's outbound webhook IPs filled into `.env` before go-live. `docs/architecture/sprint-2.md` (P2-09).
- ⚠ **Existing dev/prod databases must re-run `PermissionSeeder` + `RoleSeeder`** to pick up the `system.view` permission (added as catalog data, not schema, for the Health/Sync Logs pages) — not run automatically anywhere. `docs/architecture/sprint-2.md` (P2-12).
- ⚠ **Real synced customer data has `province`/`city`/`first_seen_at` = NULL and `lifecycle_stage` = `prospect`** for all 19,586 dev customers (address/lifecycle sync not yet built as of Sprint 3) — several customer-list filters return nothing useful until that lands. `docs/architecture/sprint-3.md` (P3-01).
- ⚠ **Trigram (`pg_trgm`) search on `display_name` is a no-op for Persian text** — the dev Postgres cluster was created with `lc_ctype = C`, so no trigrams are extracted from Persian strings; search silently falls back to a sequential scan (~430-465ms at 19.5k rows, already past the PRD §23 <500ms/15k-rows target). Needs a Unicode-aware cluster locale (`fa_IR.UTF-8`/ICU) or a different Persian search approach — infrastructure decision, not yet made. `docs/architecture/sprint-3.md` (P3-01).
- ⚠ **Three Woo orders (#19627, #19640, #19642) have a Jalali date typed into the Gregorian `date_created` field**, landing them before every GATE 1 month and making them invisible to reconciliation (279,800 Toman realized revenue affected). Needs a Woo-side fix or an explicit mapper flag. `docs/architecture/sprint-2.md`.
- ⚠ **222 phone-less orders (0.9% of all orders, 0.69% of realized revenue)** have no review UI yet. `docs/architecture/sprint-2.md`.

## Task index

Each row links to the sprint archive file; open the file and search the heading to read the full entry.

| Task   | File        | What / why (1 line)                                               |
| ------ | ----------- | ----------------------------------------------------------------- |
| P0-01  | sprint-0.md | RTL + shadcn/ui scaffold, real installed versions recorded        |
| P0-02  | sprint-0.md | Horizon wired to Redis, queue verified                            |
| P0-03  | sprint-0.md | `PhoneNormalizer`, `JalaliDate`, Money helpers (TEST FIRST)       |
| P0-04  | sprint-0.md | PL/pgSQL Jalali conversion functions                              |
| P0-05  | sprint-0.md | Core migrations; found + fixed the UTC connection-timezone bug    |
| P0-06  | sprint-0.md | Auth + 2FA (starter kit), Persian RTL auth pages                  |
| P0-07  | sprint-0.md | Permissions (deny>allow>role>default-deny), CRITICAL test         |
| P0-08  | sprint-0.md | Audit log                                                         |
| P0-09  | sprint-0.md | Settings + AlertService                                           |
| P0-10  | sprint-0.md | Architecture arch-tests (module boundaries, layering)             |
| GATE 0 | sprint-0.md | Passed — end of Sprint 0                                          |
| P1-01  | sprint-1.md | Customers + identities + conflicts + addresses + notes            |
| P1-02  | sprint-1.md | Catalog module (products, categories, variations)                 |
| P1-03  | sprint-1.md | Orders + items + status history + refunds                         |
| P1-04  | sprint-1.md | Migrations for Metrics / Segments / Analytics / Sync / AI         |
| P1-05  | sprint-1.md | Enums pass across all modules                                     |
| P1-06  | sprint-1.md | `DemoDataSeeder` + `expected_metrics.json` fixture                |
| P2-01  | sprint-2.md | `WooClient` / `HttpWooClient` transport layer                     |
| P2-02  | sprint-2.md | `FakeWooClient` + recorded fixtures for tests                     |
| P2-03  | sprint-2.md | DTOs + Mappers + `OrderStatusMapper` (TEST FIRST)                 |
| P2-04  | sprint-2.md | `CustomerIdentityService` + identity conflicts (TEST FIRST)       |
| P2-05  | sprint-2.md | Category + Product + Variation sync                               |
| P2-06  | sprint-2.md | Order + items sync, 4-step product resolution                     |
| P2-07  | sprint-2.md | Refund sync                                                       |
| P2-08  | sprint-2.md | `SyncService`, cursor, jobs                                       |
| P2-09  | sprint-2.md | Webhook: HMAC + IP + dedupe                                       |
| P2-10  | sprint-2.md | `hm:sync`, resumable full sync in pages                           |
| P2-11  | sprint-2.md | Reconciliation service, job, monthly report                       |
| P2-12  | sprint-2.md | System pages: Health, Sync Logs, Identity Conflicts               |
| P2-13  | sprint-2.md | Made phone-less orders usable (GATE 1 blocker #1)                 |
| P2-14  | sprint-2.md | `unit_price` from line sum/qty (GATE 1 blocker #2)                |
| GATE 1 | sprint-2.md | Passed — 2026-09-20, Sprint 2 closed                              |
| P2-15  | sprint-2.md | Reconcile job timeout 80s -> 300s (mislabeled "P3-01" in the log) |
| P3-01  | sprint-3.md | Customer list, search, filters                                    |
| P3-02  | sprint-3.md | Audited full-phone reveal                                         |
| P3-03  | sprint-3.md | Customer 360 page                                                 |
| P3-04  | sprint-3.md | Customer timeline, cursor pagination                              |
| P3-05  | sprint-3.md | Orders/products tabs + notes on Customer 360                      |
| P3-06  | sprint-3.md | Order list + detail page                                          |
| P3-07  | sprint-3.md | Product list with lifetime sales stats                            |
| P4-01  | sprint-4.md | Base aggregates + `metric_runs` (Sprint 4 start)                  |
| P4-02  | sprint-4.md | Purchase cycle + churn thresholds + low-sample guard              |
| P4-03  | sprint-4.md | `RfmCalculator` (TEST FIRST — E1/E2/E3)                           |
| P4-04  | sprint-4.md | `ClvCalculator` + confidence                                      |
| P4-05  | sprint-4.md | `ChurnCalculator` + level + Persian reason                        |
| P4-06  | sprint-4.md | `LifecycleStageResolver`                                          |
| P4-07  | sprint-4.md | `RecomputeMetricsJob` + dirty flag + listener (Sprint 4 close)    |
| P4-08  | sprint-4.md | Metrics on Customer 360 + RFM page + GATE 2                       |
| Sprint 5 setup | sprint-5.md | Post-Sprint-4-merge finding: dev `customer_metrics` was empty until `metrics:recompute` was run |
| P5-01  | sprint-5.md | Field + operator whitelist (Sprint 5 start)                       |
| P5-02  | sprint-5.md | `RuleValidator` (TEST FIRST)                                      |
| P5-03  | sprint-5.md | `RuleCompiler` (TEST FIRST) + GATE 3 passed                       |
| P5-04  | sprint-5.md | `SegmentService`: evaluate / preview / export                     |
| P5-05 (pre) | sprint-5.md | `PostgresStatementTimeout` revisited — the extra Rule 7 exception was removable (`set_config` takes bound params) |
| P5-05  | sprint-5.md | RuleBuilder UI + preview flow                                     |
| P5-06 (pre) | sprint-5.md | Two small fixes before P5-06                                      |
| P5-06  | sprint-5.md | Segment pages (List / Create / Edit / Detail / Delete)            |
| P5-07  | sprint-5.md | `DefaultSegmentSeeder` (11 of 12 seed segments)                   |
