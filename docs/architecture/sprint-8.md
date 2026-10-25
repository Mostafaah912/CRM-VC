# Sprint 8

## P8-01 — Server hardening

Spec: PRD §21 (T1, T9, T10 + hardening checklist), §25 P8-01. Full checklist and VPS run-book: `ARCHITECTURE.md` → "Hardening".

**App layer (all covered by `tests/Feature/Hardening/*` and `tests/Arch/NoDangerousInnerHtmlTest.php`, real PostgreSQL):**
- Horizon: `viewHorizon` gate = `User::hasRole(Owner)`; `Horizon::auth()` overridden so `local` is not an open door. Owner 200; manager/analyst/support/viewer/guest 403.
- `SecurityHeaders` (web group): CSP with per-request nonce, X-Frame-Options DENY, nosniff, Referrer-Policy, HSTS only in production. `app.blade.php` inline script/style carry the nonce.
- Login lockout: did **not** exist (only Fortify's 5/min throttle). Added `LoginLockout` + `AuthenticateUser` (Fortify `authenticateUsing`) + `Failed` listener: 10 failures per e-mail -> locked 15 min, right password refused while locked, success resets.
- 2FA: the Fortify feature existed but was optional. `RequireTwoFactor` (web group) + `TwoFactorPolicy` now confine Owner/Manager without a confirmed factor to the security page/2FA routes/logout.
- `ProductionSafety::assertDebugIsOff()` in `AppServiceProvider::boot()`.
- T10: arch test scanning `resources/js` (0 hits today).

**Ambiguities / defaults:** lockout is cache-based (no migration allowed); lockout window counts from the first failure; `SECURITY_ENFORCE_2FA` pinned off in phpunit.xml only; sprint branch named `sprint/8-hardening` as instructed (HEAD started on `sprint/8-launch`).

**Server files (generated only, never run):** `deploy/harden.sh` (idempotent, `--dry-run`, refuses to disable password SSH without an authorized key, asks for confirmation of a key test, drop-in named `00-` so cloud-init cannot override it), `deploy/nginx.conf.example`, `deploy/verify-hardening.sh`. Only `bash -n` and `harden.sh --dry-run` were run, on the dev Mac.

## P8-02 — Encrypted off-server backup + restore test

Spec: PRD §21 Backup, §25 P8-02. Runbook and owner checklist: `ARCHITECTURE.md` -> "Backup & Restore".

- `backup:run` / `backup:restore-test` are thin commands over `Core\Services\Backup\{BackupService, RestoreTestService, BackupRetention, BackupStatusService}`; age (`AgeEncryptor`) and gpg (`GpgEncryptor`) encryptors build shell fragments that read everything from env vars (nothing interpolated). Dump -> encrypt and decrypt -> restore are pipelines, so plaintext never touches disk.
- New: `config/backup.php`, disks `backup_local`/`backup_s3`, `league/flysystem-aws-s3-v3`, `AlertKind` x4 (BackupFailure, BackupStale, RestoreTestFailure, RestoreTestOverdue), `AuditService::latestAt/earliestAt`, `SystemHealthPage` (controller still calls one service), health page card. **No migration**: last backup / last restore test are read from `audit_logs`.
- Decisions: file names are UTC minutes; weekly copy decided on the Tehran Saturday; `BACKUP_ENABLED` defaults off so dev/CI never alert; the `--no-acl` dump carries no role grants (re-run `hm:ai-setup-readonly` after a restore); the restore comparison is "restored must cover live-at-backup-time", so deletions after a backup never cause a false failure.
- Tests (`tests/Feature/Backup`, real PostgreSQL + real age): full run -> ciphertext (age header, no `PGDMP`, no plaintext sentinel) -> restore test; wrong key; checksum tamper; missing/incomplete restore; target guard; failed dump leaves nothing on disk or in temp; retention exactly 30 d / 12 w; stale/overdue alerts; health page; schedule 02:00. Mutations (row-count comparison, checksum check) caught. The flow tests need the `age` binary (`BACKUP_AGE_BINARY` or PATH) and are skipped without it — CI must install age.
- Not verified: a real S3 upload, gpg, a run against the production DB size (timing of the 02:00 dump vs the 03:00 chain).

## P8-03 — Deploy script + staging

Spec: PRD §25 Sprint 8, §24 DoD ("works on staging"), D13, D14. Runbook and checklist: `ARCHITECTURE.md` -> "Deploy & Staging".

- Prerequisite run: the previous full suite was started while the P8-03 files were being added and then collided with a second test run on the same `heymode_testing` database (the suite cannot run twice in parallel); the authoritative full run is the final one below.
- New: `deploy/deploy.sh`, `.env.staging.example`, `hm:staging:seed` (`StagingSeedCommand`), `staging` shared Inertia prop + `StagingBanner` (inside `AppShell`, both layouts), `X-Robots-Tag` in staging, `WebhookVerdict::Disabled` + `woo.webhook_enabled` (default off in staging), `ai.enabled` needs `AI_ALLOW_IN_STAGING` in staging, `ProductionSafety` now also covers staging, CI step for age + PG client.
- Decisions: the production confirmation is a typed phrase that needs a TTY (no `--yes` flag on purpose); backups run for staging too unless `--skip-backup` (spec); the backup runs *before* maintenance mode (shorter downtime; it dumps the pre-migration database); a failure *before* the first change does not write a `failed` line, so it cannot shadow the last good release's rollback target; rollback never touches the database.
- Arch tests updated deliberately: the command-file allow-list gained `StagingSeedCommand`.
- Not verified: any real server run (checkout/composer/npm/migrate/FPM reload, `flock`, `sudo systemctl`), shellcheck, the new CI step (first push).


## P8-03c — Tolerant catalog sync (found on the first real staging sync, 2026-10-24)

Finding: 228 of 2,796 Woo products were rejected by the catalog sync — 124 on a variation SKU "already owned", 104 on a price that is not a whole-Toman string — which left 14,270 of 65,743 order lines (22%, 99.5% of the unresolved ones that carry a Woo product id) without a catalog product. A further 6,116 lines (9%) have no product id in Woo itself (product deleted there).

- SKU: Woo returns the PARENT's SKU on a variation that has none of its own (54 of 54 sampled variations of 12 rejected parents). The "owner" in the error was the parent written earlier in the same transaction. Rule now: a variation SKU equal to its parent's is inherited -> stored NULL (still linked to the parent); a SKU another variation/product really owns -> stored NULL + a `Catalog variation SKU is already owned…` warning. The partial unique indexes stay. Product-level SKU conflicts still reject (none were seen).
- Price (display data, never revenue): `PayloadReader::lenientMoney()` — empty -> NULL; `"12500.00"` -> 12500; a real fraction (`"276487.5"`, 7 of 46 inspected) or anything else non-canonical -> NULL + a `Catalog price is not a whole-Toman amount` warning carrying the raw value. Never rounded. Mappers stay pure (arch rule): the raw value rides on `ProductDto/VariationDto::droppedPrice` and `CatalogSyncService` logs it. Order totals and every other money field stay strict.
- Replaced the P6 `nullableDecimalMoney` (product price only). Tests that locked the old refusals were rewritten; the two atomic-rollback tests now use `variationUnderAnotherProduct` as the structural failure.

## P8-03f — clv_estimated from distinct purchase days, capped; value at risk from revenue (found on staging, 2026-10-24)

Finding: the dashboard "ارزش در خطر" showed 32.8 bn Toman — more than the 13.4 bn the whole store had ever sold. Its inputs were `clv_estimated` = `aov x 0.17 x (365 / purchase_cycle_days) x 2`, and `purchase_cycle_days` is the median gap between a customer's consecutive ORDERS. 36 customers have a cycle under 0.1 day (56 under 1 day): two orders minutes apart (median 15.7 min over 42 pairs) — a second checkout, or a retry after a cancellation, only 3 of 42 pairs with identical totals. One such customer (1.8 m Toman lifetime revenue) got 5.7 bn; the single largest estimate in the table was 205.5 bn; across all customers the estimates summed to 396 bn and 631 customers (275 of them high/lost risk) had an estimate above their own lifetime revenue. The 100 largest estimates made 98% of the 32.8 bn.

Decisions (product owner):
- `ClvCalculator` computes `clv_estimated` from DISTINCT PURCHASE DAYS (Asia/Tehran dates of realized, not fully refunded orders): cycle = median gap between them (1..730 days), at least `metrics.clv.min_purchase_days` (2) of them, else NULL; capped at `metrics.clv.cap_multiple` (3) x `clv_historical`. `purchase_cycle_days`, churn, `expected_next_order_at` and the "سررسید خرید مجدد" segment are NOT changed (open question: they still use the order-gap cycle; the 36 minutes-apart customers are scored from it).
- PRD §13 amended in place (dated block; the original formula stays visible as superseded). Config knobs, not literals: `METRICS_CLV_MIN_PURCHASE_DAYS`, `METRICS_CLV_CAP_MULTIPLE`.
- Gate 2 fixture updated DELIBERATELY, not regenerated: exactly 20 `clv_estimated` values in `tests/fixtures/expected_metrics.json` changed (no other field), every one of them hitting the 3x cap (the demo customers have 2-3 orders and 35-70 day cycles). The new values were confirmed by THREE independent implementations before the edit: the real pipeline, the PHP oracle (`tests/Support/ReferenceMetrics`) and the plain-SQL reference in `ExpectedMetricsFixtureTest` — the latter two were rewritten to the new definition first.
- Consequence to know about: with the 3x cap the projection is bounded by `clv_historical` for most customers (`estimate / history = 730 / (cycle x orders)`, above 3 whenever cycle x orders < 243 days). On staging, 3x cuts the sum of the distinct-day estimates by ~85% (10.0 bn -> 1.5 bn); 5x would give 2.3 bn. Tune via `METRICS_CLV_CAP_MULTIPLE` (no code change), then run a full recompute.
- Dashboard value at risk = `SUM(total_revenue)` of `high` and of `lost` customers, two figures (P8-03e); the AI tool `get_churn_report` now returns the same two figures (`value_at_risk: {high, lost}`, each hidden below the small-group floor on its own, `value_at_risk_means` = lifetime revenue). The AI prompts (`analyst.daily.v4` / `analyst.weekly.v4`, rule 15) still describe value_at_risk as an "estimated lifetime value" — stale wording, left unchanged on purpose (a prompt change bumps its version and the prompt lock); needs a v5.
- Metrics guide page reads `min_purchase_days` / `cap_multiple` from config.

## P8-03g — Catalog out of the nightly chain, incremental + resumable; bounded Woo requests; the running month in the reconcile (2026-10-25)

Finding (first real nightly on staging): the 03:00 chain's first step, `CatalogSyncJob`, ran 30 min 12 s and was killed by its 1800 s timeout (`TimeoutExceededException`); `Bus::chain` stopped, so metrics / daily metrics / cohort / segments / reconcile did not run, the derived tables stayed a day old and the `nightly_chain_timeout` alert fired hourly. The P7-08 "fail-soft" could not help: a timeout kill ends the process, nothing in `handle()` can catch it. 29 `Woo request failed, will retry` warnings (`status: null` = connection/timeout, never a 429/403) started ~70 s into the job.

Measured (70 GET requests from the staging server, 2026-10-25, after the outage had passed): 70/70 succeeded, zero 429/403/5xx; DNS+connect 0.09 s, TLS 0.19 s. Time is Woo's own processing, not the network or the payload: `orders?per_page=1` (a few bytes) median 2.49 s; a 1.4 MB product page 3.71 s vs 2.93 s with `_fields` (32 KB) — `_fields` buys ~20 %, the NUMBER of requests is the cost. A full catalog read is 56 product pages + 251 `/variations` calls + 4 category pages = ~311 requests = ~900 s of waiting; one stall (30 s x 5 attempts + 160 s of backoff = 310 s per request) is enough to cross 1800 s. `modified_after` and `orderby=id` work on `products` (products modified in the last 1 / 7 / 30 days: 968 / 1195 / 1431 of 2796 — a bulk update touched ~1000 products on 10-24).

Decisions (product owner):
- `CatalogSyncJob` leaves `hm:nightly-chain`. Own schedule 01:00 Tehran: INCREMENTAL daily except Friday, FULL rebuild on Friday (Iran's weekend). Failure = its own `CatalogSyncFailed` -> SyncFailure alert, raised from the job's `failed()` hook (which the worker also calls for a timeout kill) via `CatalogSyncCheckpoint::recordFailure()` — no Woo client needed. `failSoft` is gone (nothing to be soft about any more).
- Incremental: products modified after `cursor - woo.overlap_minutes`, ordered by id, only the fields the mappers read (`_fields`). Categories are always read in full. Limitation (documented, covered by the Friday rebuild): a variation changed without bumping its parent's modified date is not seen by an incremental run.
- Resumable: `sync_cursors.checkpoint` (jsonb, new migration, NULL when no run is in flight) holds mode, frozen `cursor_to`, `modified_after`, phase and the next products page; written after every fully written page. A checkpoint younger than 24 h is resumed over the SAME window; a `full` request over an incremental checkpoint, or an older checkpoint, starts over; a completed-run cursor older than 14 days is not trusted (full). `cursor_value` moves only on completion, to the instant the run FIRST started (CLAUDE.md sync rules). `CatalogSyncCheckpoint` is the second (and only other) writer of a cursor besides `SyncService`: `SyncRunBoundaryTest`/`SyncCommandBoundaryTest` were updated deliberately (one writer per entity).
- Bounded requests: `woo.timeout` 30 -> 20 s, `max_retries` 4 -> 3, ladder `[2, 8, 30]`: one request <= 4 x 20 + 40 = 120 s (was 310 s). PRD §10 amended. `per_page` stays 50 (100 would save ~30 % per product page; not worth the per-call override of a reserved parameter now).
- Timeout ladder, strict: `CatalogSyncJob` 1500 s < Horizon supervisor 1800 s < queue `retry_after` 1830 s (the job used to equal the supervisor's 1800 s).
- `ReconcileRecentMonthsJob` now also queues the RUNNING month, compared month-to-date up to the orders cursor minus the overlap (every order created before that has certainly been polled), stored as one row replaced nightly with `details.month_to_date = true`. Refused (nothing asked, nothing written) while the orders cursor is missing or not past the month start. GATE 1 still judges complete months only. `OrdersCursorReader` is a new read-only class so `ReconciliationService` does not name the run models.
- Test fixtures: `FakeWooClient` now ignores the shaping parameters (`_fields`, `orderby`, `order`, `modified_after`, `dates_are_gmt`) when matching a recorded exchange (they are still recorded in `requests()`), like it already ignored the frozen window.
