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
