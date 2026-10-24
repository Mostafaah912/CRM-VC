#!/usr/bin/env bash
# HeyMode Customer OS — P8-03 deploy script. Direct deploy on a VPS, no Docker (PRD D14). Run ON the server, in the app checkout,
# as the deploy user (the one that owns the checkout; it needs `sudo systemctl reload php*-fpm`).
#
#   deploy/deploy.sh staging    [--ref REF] [--skip-backup] [--dry-run]
#   deploy/deploy.sh production [--ref REF] [--dry-run]            (asks you to type a confirmation; backup is mandatory)
#   deploy/deploy.sh rollback staging|production [--dry-run]       (back to the previous ref; NEVER touches migrations)
#
# Order: preflight (clean tree, ref, .env matches) -> [production: backup:run must succeed] -> maintenance mode -> checkout ->
# composer install --no-dev -> npm ci + build -> migrate --force -> cache config/route/view/event -> horizon:terminate + reload
# PHP-FPM -> leave maintenance -> smoke check. Idempotent: re-running the same ref is harmless.
# --dry-run prints every step and changes nothing.
#
# Environment overrides: APP_DIR PHP_BIN COMPOSER_BIN NPM_BIN PHP_VERSION (default 8.3 -> php8.3-fpm) PHP_FPM_SERVICE SMOKE_URL SMOKE_RETRIES
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
PHP_VERSION="${PHP_VERSION:-8.3}"   # same knob as deploy/provision.sh
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php${PHP_VERSION}-fpm}"
SMOKE_RETRIES="${SMOKE_RETRIES:-6}"
LOG_DIR="$APP_DIR/storage/logs"
RELEASES_LOG="$LOG_DIR/releases.log"
DEPLOY_LOG="$LOG_DIR/deploy.log"

MODE="deploy"; TARGET=""; REF=""; DRY_RUN=0; SKIP_BACKUP=0
MAINTENANCE_ON=0; CODE_SWITCHED=0; FINISHED=0; RELEASE_STARTED=0; PREVIOUS_SHA=""; TARGET_SHA=""

usage() { sed -n '2,14p' "$0"; exit "${1:-2}"; }

# ------------------------------------------------------------------ args
[ $# -ge 1 ] || usage
if [ "$1" = "rollback" ]; then MODE="rollback"; shift; fi
[ $# -ge 1 ] || usage
TARGET="$1"; shift
case "$TARGET" in staging|production) ;; *) echo "Environment must be 'staging' or 'production' (got '$TARGET')." >&2; usage ;; esac
while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)     DRY_RUN=1 ;;
        --skip-backup) SKIP_BACKUP=1 ;;
        --ref)         shift; REF="${1:-}"; [ -n "$REF" ] || { echo "--ref needs a value" >&2; exit 2; } ;;
        -h|--help)     usage 0 ;;
        *)             echo "Unknown option: $1" >&2; usage ;;
    esac
    shift
done
if [ "$SKIP_BACKUP" = 1 ] && [ "$TARGET" = "production" ]; then
    echo "--skip-backup is refused for production: a production deploy always starts with a successful backup." >&2; exit 2
fi
[ -n "$REF" ] || REF="main"

# ------------------------------------------------------------------ helpers
ts()  { date '+%Y-%m-%d %H:%M:%S'; }
log() {
    local line; line="[$(ts)] [$TARGET] $*"
    printf '%s\n' "$line"
    if [ "$DRY_RUN" = 0 ] && [ -d "$LOG_DIR" ]; then printf '%s\n' "$line" >> "$DEPLOY_LOG"; fi
}
die() { log "ERROR: $*"; exit 1; }
warn_or_die() { if [ "$DRY_RUN" = 1 ]; then log "WARN (would abort a real run): $*"; else die "$*"; fi; }

# run <description> <command...> — the ONE place a state-changing command executes.
run() {
    local desc="$1"; shift
    if [ "$DRY_RUN" = 1 ]; then log "[dry-run] $desc: $*"; return 0; fi
    log "$desc"
    "$@"
}
artisan() { "$PHP_BIN" "$APP_DIR/artisan" "$@"; }
sudo_cmd() { if [ "$(id -u)" -eq 0 ]; then "$@"; else sudo -n "$@"; fi; }
env_value() { # key -> value from $APP_DIR/.env (no sourcing: .env is not shell)
    [ -f "$APP_DIR/.env" ] || return 0
    grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" || true
}

# Is the app usable from the CLI right now: it boots, reaches the database and has no pending migration?
app_is_healthy() {
    local out
    out="$(artisan migrate:status --no-interaction 2>&1)" || return 1
    ! printf '%s' "$out" | grep -q 'Pending'
}

on_exit() {
    local code=$?
    [ "$code" -eq 0 ] && [ "$FINISHED" = 1 ] && return 0
    [ "$DRY_RUN" = 1 ] && return 0
    trap - EXIT
    log "FAILED (exit $code)."
    # Only a deploy that actually started (wrote its 'started' line) records 'failed' — a failure BEFORE any change must not shadow the last good release's rollback target.
    if [ "$RELEASE_STARTED" = 1 ]; then record_release "failed"; fi
    if [ "$MAINTENANCE_ON" = 1 ]; then
        if app_is_healthy; then
            log "The app boots, reaches the database and has no pending migration -> leaving maintenance mode."
            artisan up || log "WARNING: could not run 'artisan up' — run it by hand."
            [ "$CODE_SWITCHED" = 1 ] && log "NOTE: the new code IS checked out (${TARGET_SHA:0:10}); if it misbehaves run: deploy/deploy.sh rollback $TARGET"
        else
            log "The app is NOT healthy (cannot boot, database unreachable, or a migration is pending)."
            log "It is being LEFT IN MAINTENANCE MODE on purpose. Look at $DEPLOY_LOG and storage/logs/laravel*.log, then either fix forward and re-run"
            log "this script, or run: deploy/deploy.sh rollback $TARGET   (rollback never reverts migrations — see its message)."
        fi
    fi
    exit "$code"
}
trap on_exit EXIT

record_release() { # status
    [ "$DRY_RUN" = 1 ] && return 0
    mkdir -p "$LOG_DIR"
    printf '%s\t%s\tfrom=%s\tto=%s\t%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$TARGET" "$PREVIOUS_SHA" "${TARGET_SHA:-unknown}" "$1" >> "$RELEASES_LOG"
}

smoke_check() {
    local url="${SMOKE_URL:-$(env_value APP_URL)}"
    [ -n "$url" ] || die "No SMOKE_URL and no APP_URL in .env — cannot run the smoke check."
    url="${url%/}"
    local path code i
    for path in /up /login; do
        code=000
        for i in $(seq 1 "$SMOKE_RETRIES"); do
            code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$url$path" || echo 000)"
            [ "$code" = "200" ] && break
            sleep 3
        done
        [ "$code" = "200" ] || die "Smoke check failed: $url$path answered HTTP $code (expected 200)."
        log "Smoke check OK: $url$path -> 200"
    done
}

# ------------------------------------------------------------------ preflight
cd "$APP_DIR"
command -v git >/dev/null || die "git not found"
git rev-parse --git-dir >/dev/null 2>&1 || die "$APP_DIR is not a git checkout"

if [ "$DRY_RUN" = 0 ] && command -v flock >/dev/null; then
    mkdir -p "$LOG_DIR"
    exec 9>"$APP_DIR/storage/deploy.lock"
    flock -n 9 || die "Another deploy is running (storage/deploy.lock)."
fi

log "Mode=$MODE env=$TARGET ref=$REF dry_run=$DRY_RUN app_dir=$APP_DIR"

ENV_FILE_ENV="$(env_value APP_ENV)"
if [ "$ENV_FILE_ENV" != "$TARGET" ]; then
    warn_or_die ".env says APP_ENV='${ENV_FILE_ENV:-<missing>}' but you asked to deploy '$TARGET'. Refusing: wrong server or wrong .env."
fi
if [ "$TARGET" != "production" ] && [ "$(env_value DB_DATABASE)" = "" ]; then
    warn_or_die ".env has no DB_DATABASE."
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    warn_or_die "The working tree has uncommitted changes. Commit/stash/reset them first (a deploy never overwrites local edits)."
fi
PREVIOUS_SHA="$(git rev-parse HEAD)"

if [ "$MODE" = "rollback" ]; then
    [ -f "$RELEASES_LOG" ] || die "No $RELEASES_LOG — nothing to roll back to."
    LAST="$(awk -F'\t' -v e="$TARGET" '$2==e' "$RELEASES_LOG" | tail -n1)"
    [ -n "$LAST" ] || die "No release recorded for $TARGET."
    TARGET_SHA="$(printf '%s' "$LAST" | sed -n 's/.*\tfrom=\([0-9a-f]*\)\t.*/\1/p')"
    [ -n "$TARGET_SHA" ] || die "Cannot read the previous ref from: $LAST"
    git cat-file -e "${TARGET_SHA}^{commit}" 2>/dev/null || die "Previous ref $TARGET_SHA is not in this checkout (git fetch?)."
    log "Rolling back $TARGET: ${PREVIOUS_SHA:0:10} -> ${TARGET_SHA:0:10}"
else
    run "Fetching origin" git fetch --tags --prune origin
    if git rev-parse --verify --quiet "origin/$REF^{commit}" >/dev/null; then
        TARGET_SHA="$(git rev-parse "origin/$REF^{commit}")"
    elif git rev-parse --verify --quiet "$REF^{commit}" >/dev/null; then
        TARGET_SHA="$(git rev-parse "$REF^{commit}")"
    else
        die "Ref '$REF' not found (branch, tag or commit)."
    fi
    log "Deploying ${PREVIOUS_SHA:0:10} -> ${TARGET_SHA:0:10} ($REF)"
fi

if [ "$TARGET" = "production" ] && [ "$DRY_RUN" = 0 ]; then
    [ -t 0 ] || die "Production needs an interactive terminal for the typed confirmation."
    printf '\n!!! PRODUCTION %s of %s -> %s !!!\nType exactly "%s production" to continue: ' "$MODE" "${PREVIOUS_SHA:0:10}" "${TARGET_SHA:0:10}" "$MODE"
    read -r answer
    [ "$answer" = "$MODE production" ] || die "Confirmation did not match. Nothing was changed."
fi

# ------------------------------------------------------------------ backup (before anything changes)
if [ "$MODE" = "deploy" ]; then
    if [ "$SKIP_BACKUP" = 1 ]; then
        log "Backup skipped (--skip-backup, $TARGET only)."
    else
        run "Backup before migrating (backup:run must succeed)" artisan backup:run || die "backup:run FAILED — deploy stopped before any change."
    fi
fi

# ------------------------------------------------------------------ switch
if [ "$MODE" = "deploy" ]; then record_release "started"; RELEASE_STARTED=1; fi
run "Entering maintenance mode" artisan down --retry=60 || die "Could not enter maintenance mode."
MAINTENANCE_ON=1

run "Checking out ${TARGET_SHA:0:10}" git checkout --detach "$TARGET_SHA"
CODE_SWITCHED=1

run "composer install --no-dev" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --no-progress
run "npm ci" "$NPM_BIN" ci --no-audit --no-fund
run "npm run build" "$NPM_BIN" run build

if [ "$MODE" = "deploy" ]; then
    run "migrate --force" artisan migrate --force
else
    log "ROLLBACK DOES NOT TOUCH THE DATABASE. If the rolled-back release added migrations, the schema is still the NEWER one."
    log "That is usually fine (additive migrations). If it is not, reverting is a deliberate, manual step:"
    log "    php artisan migrate:status        # see what ran"
    log "    php artisan migrate:rollback --step=N --force    # only after reading each migration's down(); restore from backup if unsure"
fi

for c in config:cache route:cache view:cache event:cache; do run "artisan $c" artisan "$c"; done

run "horizon:terminate (the process manager restarts it on the new code)" artisan horizon:terminate
run "Reloading $PHP_FPM_SERVICE" sudo_cmd systemctl reload "$PHP_FPM_SERVICE"

run "Leaving maintenance mode" artisan up
MAINTENANCE_ON=0

if [ "$DRY_RUN" = 1 ]; then
    log "[dry-run] smoke check: GET \${SMOKE_URL:-APP_URL}/up and /login must answer 200"
else
    smoke_check
fi

FINISHED=1
if [ "$MODE" = "rollback" ]; then record_release "rollback"; else record_release "ok"; fi
log "Done: $MODE $TARGET at ${TARGET_SHA:0:10}$([ "$DRY_RUN" = 1 ] && echo ' (dry-run: nothing was changed)')."
[ "$MODE" = "rollback" ] && log "Check horizon is back up and the app works; then record why in ARCHITECTURE.md/the sprint log."
exit 0
