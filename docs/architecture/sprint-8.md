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

