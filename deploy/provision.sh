#!/usr/bin/env bash
# HeyMode Customer OS — P8-03b server provisioning (Ubuntu 24.04). Run as root ON THE VPS, BEFORE deploy/harden.sh.
# Direct install on the host, no Docker (PRD D14). Idempotent: re-running converges, it never regenerates a secret.
#
#   sudo deploy/provision.sh --dry-run          # prints every change, applies nothing, needs no root
#   sudo deploy/provision.sh
#   sudo PHP_VERSION=8.4 STAGING_DOMAIN=staging.example.com deploy/provision.sh
#
# What it sets up (STAGING is live, PRODUCTION is only prepared and never deployed):
#   - user `deploy` (key login, limited sudo: reload php-fpm / nginx -t+reload / supervisorctl horizon-*)
#   - nginx, PHP-FPM + the Laravel extensions, PostgreSQL, Redis, Supervisor, git, unzip, composer, node, age,
#     certbot, fail2ban, ufw, unattended-upgrades, tmux — ufw/fail2ban/sshd are only INSTALLED here; harden.sh configures them
#   - 2 GB swap
#   - PostgreSQL on localhost with scram-sha-256: databases+roles heymode_staging (CREATEDB) and heymode_production, isolated from each other
#   - Redis on 127.0.0.1 with requirepass (staging uses DB 1 + prefix heymode-staging-, production DB 0 + prefix heymode-production-)
#   - /var/www/heymode-staging and /var/www/heymode-production owned by deploy
#   - a dedicated PHP-FPM pool that runs AS deploy, so .env (chmod 600, owner deploy) is readable by php-fpm, Horizon and artisan alike
#   - Supervisor program for Horizon + cron for schedule:run (production lines are written commented out)
#   - nginx staging site on port 80 with HTTP basic auth (127.0.0.1 is exempt so deploy.sh's smoke check works) and NO TLS until certbot
#
# Environment overrides:
#   PHP_VERSION (8.3)  NODE_MAJOR (22, matches CI)  DEPLOY_USER (deploy)  STAGING_DOMAIN ('' -> server_name _)  SWAP_SIZE (2G)
#   SECRETS_FILE (/root/provision-secrets.txt)  LOG_FILE (/var/log/heymode-provision.log)
#
# Secrets: generated once and stored ONLY in $SECRETS_FILE (chmod 600). They are never printed, never put on a command line
# and are masked in the log. Read them on the server with `sudo cat`, never paste them into chat/Git.
set -Eeuo pipefail

DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) sed -n '2,29p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg (use --dry-run)" >&2; exit 2 ;;
    esac
done

PHP_VERSION="${PHP_VERSION:-8.3}"
NODE_MAJOR="${NODE_MAJOR:-22}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
STAGING_DOMAIN="${STAGING_DOMAIN:-}"
SWAP_SIZE="${SWAP_SIZE:-2G}"
SECRETS_FILE="${SECRETS_FILE:-/root/provision-secrets.txt}"
LOG_FILE="${LOG_FILE:-/var/log/heymode-provision.log}"

STAGING_DIR="/var/www/heymode-staging"
PRODUCTION_DIR="/var/www/heymode-production"
PHP_SOCK="/run/php/heymode-fpm.sock"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
HTPASSWD_FILE="/etc/nginx/heymode-staging.htpasswd"
BASIC_AUTH_USER="staging"

[[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "PHP_VERSION must look like 8.3 (got '$PHP_VERSION')" >&2; exit 2; }
[[ "$NODE_MAJOR" =~ ^[0-9]+$ ]] || { echo "NODE_MAJOR must be a number (got '$NODE_MAJOR')" >&2; exit 2; }
[[ "$DEPLOY_USER" =~ ^[a-z][a-z0-9_-]*$ ]] || { echo "DEPLOY_USER is not a valid user name (got '$DEPLOY_USER')" >&2; exit 2; }
[[ "$SWAP_SIZE" =~ ^[0-9]+[MG]$ ]] || { echo "SWAP_SIZE must look like 2G or 2048M (got '$SWAP_SIZE')" >&2; exit 2; }
if [ -n "$STAGING_DOMAIN" ] && ! [[ "$STAGING_DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
    echo "STAGING_DOMAIN is not a valid host name (got '$STAGING_DOMAIN')" >&2; exit 2
fi

# ------------------------------------------------------------------ logging (timestamped, secrets masked)
ts()   { date '+%Y-%m-%d %H:%M:%S'; }
have() { command -v "$1" >/dev/null 2>&1; }
is_linux() { [ "$(uname -s)" = "Linux" ]; }

REDACT_SED=""
redact() { if [ -n "$REDACT_SED" ] && [ -s "$REDACT_SED" ]; then sed -f "$REDACT_SED"; else cat; fi; }
emit_raw() { # already-redacted line
    printf '%s\n' "$1"
    if [ "$DRY_RUN" = 0 ]; then printf '%s\n' "$1" >> "$LOG_FILE"; fi
}
log()  { emit_raw "[$(ts)] $(printf '%s' "$*" | redact)"; }
warn() { emit_raw "[$(ts)] WARN: $(printf '%s' "$*" | redact)" >&2; }
die()  { emit_raw "[$(ts)] ERROR: $(printf '%s' "$*" | redact)" >&2; exit 1; }
stream() { local line; while IFS= read -r line; do emit_raw "[$(ts)]   $line"; done; }

refresh_redaction() { # rebuild the sed script that masks every stored secret value
    [ "$DRY_RUN" = 1 ] && return 0
    [ -n "$REDACT_SED" ] || return 0
    : > "$REDACT_SED"
    [ -f "$SECRETS_FILE" ] || return 0
    local k v
    while IFS='=' read -r k v; do
        case "$k" in ''|\#*) continue ;; esac
        if [ "${#v}" -ge 8 ]; then printf 's/%s/***REDACTED***/g\n' "$v" >> "$REDACT_SED"; fi
    done < "$SECRETS_FILE"
}

on_err() { local code=$?; emit_raw "[$(ts)] FAILED at line ${BASH_LINENO[0]} (exit $code). Re-run: the script is idempotent."; }
cleanup() { [ -n "$REDACT_SED" ] && rm -f "$REDACT_SED"; return 0; }
trap on_err ERR
trap cleanup EXIT

# run <command...> — the ONE place a state-changing command executes. Never pass a secret as an argument.
run() {
    if [ "$DRY_RUN" = 1 ]; then log "(dry-run) $*"; return 0; fi
    log "+ $*"
    local rc=0
    "$@" 2>&1 | redact | stream || rc=$?
    return "$rc"
}

# run_stdin <command...> — like run, but feeds stdin (used for SQL that contains passwords; the SQL itself is never logged).
run_stdin() {
    local input; input="$(cat)"
    if [ "$DRY_RUN" = 1 ]; then log "(dry-run) $* <<< ($(printf '%s\n' "$input" | wc -l | tr -d ' ') lines of SQL, not shown)"; return 0; fi
    log "+ $* (stdin: $(printf '%s\n' "$input" | wc -l | tr -d ' ') lines, not shown)"
    local rc=0
    printf '%s\n' "$input" | "$@" 2>&1 | redact | stream || rc=$?
    return "$rc"
}

# ------------------------------------------------------------------ preflight
if [ "$DRY_RUN" = 0 ]; then
    [ "$(id -u)" -eq 0 ] || { echo "Run as root (or use --dry-run)." >&2; exit 1; }
    is_linux || { echo "This script provisions an Ubuntu server; run it there (or use --dry-run)." >&2; exit 1; }
    umask 077
    : >> "$LOG_FILE"; chmod 600 "$LOG_FILE"
    REDACT_SED="$(mktemp "${TMPDIR:-/tmp}/hm-redact.XXXXXX")"
    if have flock; then
        exec 9>/var/lock/heymode-provision.lock
        flock -n 9 || die "Another provision run is in progress."
    fi
    umask 022
fi
if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    [ "${ID:-}" = "ubuntu" ] && [ "${VERSION_ID:-}" = "24.04" ] || warn "Tested on Ubuntu 24.04; this host is '${PRETTY_NAME:-unknown}'."
fi
log "provision.sh start: dry_run=$DRY_RUN php=$PHP_VERSION node=$NODE_MAJOR deploy_user=$DEPLOY_USER domain=${STAGING_DOMAIN:-<none>}"

# ------------------------------------------------------------------ helpers
WROTE=0
write_file() { # <path> <mode> <owner:group>   (content on stdin). Sets WROTE=1 when the file changed.
    local path="$1" mode="$2" owner="$3" tmp
    tmp="$(mktemp "${TMPDIR:-/tmp}/hm-prov.XXXXXX")"
    cat > "$tmp"
    WROTE=0
    if [ -f "$path" ] && cmp -s "$tmp" "$path"; then
        log "unchanged: $path"
        if [ "$DRY_RUN" = 0 ]; then chmod "$mode" "$path"; chown "$owner" "$path"; fi
        rm -f "$tmp"; return 0
    fi
    WROTE=1
    if [ "$DRY_RUN" = 1 ]; then
        log "(dry-run) write $path (mode $mode, owner $owner, $(wc -l < "$tmp" | tr -d ' ') lines)"
        rm -f "$tmp"; return 0
    fi
    mkdir -p "$(dirname "$path")"
    install -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$tmp" "$path"
    rm -f "$tmp"
    log "wrote: $path"
}

# set_conf_line <file> <regex-of-the-directive> <full-line>  — replace the first UNcommented match (drop duplicates) or append.
# Done in bash so the line (it may hold the Redis password) never appears on a command line.
set_conf_line() {
    local file="$1" regex="$2" line="$3" tmp l found=0
    if [ ! -f "$file" ]; then
        if [ "$DRY_RUN" = 1 ]; then log "(dry-run) $file: ensure a line for '${line%% *}'"; return 0; fi
        die "$file is missing"
    fi
    tmp="$(mktemp "${TMPDIR:-/tmp}/hm-conf.XXXXXX")"
    while IFS= read -r l || [ -n "$l" ]; do
        if [[ "$l" =~ $regex ]]; then
            if [ "$found" = 0 ]; then printf '%s\n' "$line" >> "$tmp"; found=1; fi
        else
            printf '%s\n' "$l" >> "$tmp"
        fi
    done < "$file"
    [ "$found" = 1 ] || printf '%s\n' "$line" >> "$tmp"
    WROTE=0
    if cmp -s "$tmp" "$file"; then log "unchanged: $file (${line%% *})"; rm -f "$tmp"; return 0; fi
    WROTE=1
    if [ "$DRY_RUN" = 1 ]; then log "(dry-run) $file: set '${line%% *}'"; rm -f "$tmp"; return 0; fi
    cp -n "$file" "$file.pre-provision" || true
    cat "$tmp" > "$file"   # keep the file's owner/mode
    rm -f "$tmp"
    log "updated: $file (${line%% *})"
}

pkg_installed() { have dpkg-query && dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'; }
apt_install() {
    local missing="" p
    for p in "$@"; do pkg_installed "$p" || missing="$missing $p"; done
    if [ -z "$missing" ]; then log "already installed: $*"; return 0; fi
    # shellcheck disable=SC2086
    run env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get install -y -qq -o Dpkg::Lock::Timeout=180 -o Dpkg::Options::=--force-confold $missing
}

secret_get() { # name -> value; creates (and stores) it on first use; a dry-run only shows a placeholder
    local v=""
    if [ -f "$SECRETS_FILE" ]; then v="$(grep -E "^$1=" "$SECRETS_FILE" | tail -n1 | cut -d= -f2- || true)"; fi
    if [ -n "$v" ]; then printf '%s' "$v"; return 0; fi
    if [ "$DRY_RUN" = 1 ]; then printf '<%s:generated-on-real-run>' "$1"; return 0; fi
    v="$(openssl rand -hex 24)"
    ( umask 077; printf '%s=%s\n' "$1" "$v" >> "$SECRETS_FILE" )
    chmod 600 "$SECRETS_FILE"
    refresh_redaction
    printf '%s' "$v"
}

# ------------------------------------------------------------------ 1. secrets
step_secrets() {
    log "== Secrets (values are never printed; file: $SECRETS_FILE, chmod 600) =="
    local n
    for n in DB_STAGING_PASSWORD DB_PRODUCTION_PASSWORD DB_AI_READONLY_PASSWORD REDIS_PASSWORD STAGING_BASIC_AUTH_PASSWORD; do
        secret_get "$n" >/dev/null
    done
    if [ "$DRY_RUN" = 0 ]; then
        if ! grep -q '^STAGING_BASIC_AUTH_USER=' "$SECRETS_FILE"; then
            printf 'STAGING_BASIC_AUTH_USER=%s\n' "$BASIC_AUTH_USER" >> "$SECRETS_FILE"
            printf '# Redis: staging REDIS_DB=1 REDIS_PREFIX=heymode-staging-  |  production REDIS_DB=0 REDIS_PREFIX=heymode-production-\n' >> "$SECRETS_FILE"
        fi
        chmod 600 "$SECRETS_FILE"
        refresh_redaction
        log "Secrets file ready ($(grep -c '=' "$SECRETS_FILE") entries)."
    else
        log "(dry-run) would create $SECRETS_FILE with 5 generated passwords + the basic-auth user name"
    fi
}

# ------------------------------------------------------------------ 2. apt + packages
step_packages() {
    log "== Packages =="
    run env DEBIAN_FRONTEND=noninteractive apt-get update -qq -o Dpkg::Lock::Timeout=180
    apt_install ca-certificates curl gnupg openssl software-properties-common

    local cand=""
    if have apt-cache; then cand="$(apt-cache policy "php${PHP_VERSION}-fpm" 2>/dev/null | awk '/Candidate:/ {print $2}')"; fi
    if have apt-cache && { [ -z "$cand" ] || [ "$cand" = "(none)" ]; }; then
        log "php${PHP_VERSION} is not in the distro archive -> adding ppa:ondrej/php (third-party PPA)"
        run add-apt-repository -y ppa:ondrej/php
        run env DEBIAN_FRONTEND=noninteractive apt-get update -qq -o Dpkg::Lock::Timeout=180
    else
        log "php${PHP_VERSION} candidate: ${cand:-<checked on the server>}"
    fi

    apt_install nginx postgresql postgresql-client redis-server supervisor git unzip cron \
        age certbot python3-certbot-nginx fail2ban ufw unattended-upgrades tmux
    apt_install "php${PHP_VERSION}-cli" "$PHP_FPM_SERVICE" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-redis" "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-gd"
    run update-alternatives --set php "/usr/bin/php${PHP_VERSION}"

    if [ "$DRY_RUN" = 0 ]; then
        local have_mods missing="" m
        have_mods="$("/usr/bin/php${PHP_VERSION}" -m | tr 'A-Z' 'a-z')"
        for m in pdo_pgsql pgsql redis mbstring xml curl zip intl bcmath gd pcntl posix dom fileinfo tokenizer ctype openssl; do
            printf '%s\n' "$have_mods" | grep -qx "$m" || missing="$missing $m"
        done
        [ -z "$missing" ] || die "PHP ${PHP_VERSION} is missing extensions:$missing"
        log "PHP ${PHP_VERSION}: every required extension is loaded (pdo_pgsql redis mbstring xml curl zip intl bcmath gd pcntl posix ...)"
    else
        log "(dry-run) would verify with 'php -m': pdo_pgsql pgsql redis mbstring xml curl zip intl bcmath gd pcntl posix dom fileinfo tokenizer ctype openssl"
    fi
}

step_composer() {
    log "== Composer =="
    if have composer; then log "composer present: $(COMPOSER_ALLOW_SUPERUSER=1 composer --version 2>/dev/null | head -n1)"; return 0; fi
    if [ "$DRY_RUN" = 1 ]; then log "(dry-run) would install composer from getcomposer.org after verifying the installer's sha384 signature"; return 0; fi
    local tmp expected actual
    tmp="$(mktemp -d "${TMPDIR:-/tmp}/hm-composer.XXXXXX")"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    actual="$("/usr/bin/php${PHP_VERSION}" -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
    [ -n "$expected" ] && [ "$expected" = "$actual" ] || { rm -rf "$tmp"; die "Composer installer signature mismatch — not installing."; }
    run env COMPOSER_ALLOW_SUPERUSER=1 "/usr/bin/php${PHP_VERSION}" "$tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp"
}

step_node() {
    log "== Node.js ${NODE_MAJOR}.x (NodeSource apt repo, signed key) =="
    local cur=""
    if have node; then cur="$(node -v | sed -e 's/^v//' -e 's/\..*//')"; fi
    if [ "$cur" = "$NODE_MAJOR" ]; then log "node $(node -v) already installed"; return 0; fi
    run install -d -m 755 /etc/apt/keyrings
    run bash -c 'curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg && chmod 644 /etc/apt/keyrings/nodesource.gpg'
    printf 'deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_%s.x nodistro main\n' "$NODE_MAJOR" \
        | write_file /etc/apt/sources.list.d/nodesource.list 0644 root:root
    run env DEBIAN_FRONTEND=noninteractive apt-get update -qq -o Dpkg::Lock::Timeout=180
    run env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get install -y -qq -o Dpkg::Lock::Timeout=180 nodejs
}

# ------------------------------------------------------------------ 3. swap
step_swap() {
    log "== Swap ($SWAP_SIZE) =="
    if have swapon && swapon --show --noheadings 2>/dev/null | grep -q .; then log "swap already active"; return 0; fi
    if [ "$DRY_RUN" = 1 ] || [ ! -f /swapfile ]; then
        run fallocate -l "$SWAP_SIZE" /swapfile
        run chmod 600 /swapfile
        run mkswap /swapfile
    else
        log "/swapfile exists but is not active: enabling it as is"
        run chmod 600 /swapfile
    fi
    run swapon /swapfile
    run bash -c "grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab"
    printf 'vm.swappiness=10\n' | write_file /etc/sysctl.d/99-heymode-swap.conf 0644 root:root
    run sysctl -q -w vm.swappiness=10
}

# ------------------------------------------------------------------ 4. deploy user + limited sudo
step_deploy_user() {
    log "== User $DEPLOY_USER (key login, limited sudo) =="
    if id "$DEPLOY_USER" >/dev/null 2>&1; then log "user $DEPLOY_USER exists"; else run adduser --disabled-password --gecos "" "$DEPLOY_USER"; fi
    local home="/home/$DEPLOY_USER"
    run install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$home/.ssh"
    if [ "$DRY_RUN" = 1 ]; then
        log "(dry-run) would append the public keys from /root/.ssh/authorized_keys to $home/.ssh/authorized_keys (mode 600)"
    elif [ -s /root/.ssh/authorized_keys ]; then
        touch "$home/.ssh/authorized_keys"
        local line added=0
        while IFS= read -r line || [ -n "$line" ]; do
            [ -n "$line" ] || continue
            if ! grep -qxF -- "$line" "$home/.ssh/authorized_keys"; then printf '%s\n' "$line" >> "$home/.ssh/authorized_keys"; added=$((added + 1)); fi
        done < /root/.ssh/authorized_keys
        chown "$DEPLOY_USER:$DEPLOY_USER" "$home/.ssh/authorized_keys"; chmod 600 "$home/.ssh/authorized_keys"
        log "authorized_keys for $DEPLOY_USER: $added key(s) added from root's"
    else
        warn "/root/.ssh/authorized_keys is empty or missing: add a public key to $home/.ssh/authorized_keys yourself"
    fi

    local content
    content="# HeyMode deploy user — managed by deploy/provision.sh. Exactly what deploy/deploy.sh needs, nothing else.
Cmnd_Alias HM_PHP    = /usr/bin/systemctl reload ${PHP_FPM_SERVICE}, /usr/bin/systemctl restart ${PHP_FPM_SERVICE}
Cmnd_Alias HM_NGINX  = /usr/sbin/nginx -t, /usr/bin/systemctl reload nginx
Cmnd_Alias HM_SUPERV = /usr/bin/supervisorctl status, /usr/bin/supervisorctl status *, /usr/bin/supervisorctl start horizon-*, /usr/bin/supervisorctl stop horizon-*, /usr/bin/supervisorctl restart horizon-*, /usr/bin/supervisorctl reread, /usr/bin/supervisorctl update
${DEPLOY_USER} ALL=(root) NOPASSWD: HM_PHP, HM_NGINX, HM_SUPERV"
    if [ "$DRY_RUN" = 0 ]; then
        local t; t="$(mktemp "${TMPDIR:-/tmp}/hm-sudoers.XXXXXX")"
        printf '%s\n' "$content" > "$t"
        visudo -cf "$t" >/dev/null || { rm -f "$t"; die "Generated sudoers failed visudo -c; nothing installed."; }
        rm -f "$t"
    fi
    printf '%s\n' "$content" | write_file /etc/sudoers.d/heymode-deploy 0440 root:root
}

# ------------------------------------------------------------------ 5. PostgreSQL
step_postgres() {
    log "== PostgreSQL (localhost only, scram-sha-256, databases heymode_staging + heymode_production) =="
    local dir=""
    if is_linux; then dir="$(ls -d /etc/postgresql/*/main 2>/dev/null | sort -V | tail -n1 || true)"; fi
    if [ -z "$dir" ]; then
        [ "$DRY_RUN" = 1 ] || die "PostgreSQL config dir not found after install."
        dir="/etc/postgresql/<version>/main"
    fi
    local restart=0
    printf "listen_addresses = 'localhost'\npassword_encryption = 'scram-sha-256'\n" | write_file "$dir/conf.d/90-heymode.conf" 0644 postgres:postgres
    [ "$WROTE" = 1 ] && restart=1
    if [ "$DRY_RUN" = 0 ]; then
        grep -Eq "^[[:space:]]*include_dir[[:space:]]*=[[:space:]]*'conf.d'" "$dir/postgresql.conf" || { printf "include_dir = 'conf.d'\n" >> "$dir/postgresql.conf"; restart=1; log "added include_dir to postgresql.conf"; }
        if grep -Eq '^(host|hostssl|hostnossl)[[:space:]].*[[:space:]](md5|password|trust)([[:space:]]|$)' "$dir/pg_hba.conf"; then
            cp -n "$dir/pg_hba.conf" "$dir/pg_hba.conf.pre-provision" || true
            sed -i -E '/^(host|hostssl|hostnossl)[[:space:]]/ s/[[:space:]](md5|password|trust)([[:space:]]|$)/ scram-sha-256\2/' "$dir/pg_hba.conf"
            restart=1; log "pg_hba.conf: md5/password/trust host rules -> scram-sha-256"
        else
            log "pg_hba.conf: no weak host rule"
        fi
    else
        log "(dry-run) pg_hba.conf: every host rule using md5/password/trust would become scram-sha-256"
    fi
    run systemctl enable postgresql
    if [ "$restart" = 1 ]; then run systemctl restart postgresql; else run systemctl start postgresql; fi
    run bash -c 'for i in $(seq 1 30); do pg_isready -q && exit 0; sleep 1; done; echo "PostgreSQL did not become ready" >&2; exit 1'

    local pw_s pw_p
    pw_s="$(secret_get DB_STAGING_PASSWORD)"; pw_p="$(secret_get DB_PRODUCTION_PASSWORD)"
    run_stdin runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 <<SQL
SELECT 'CREATE ROLE heymode_staging LOGIN' WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'heymode_staging')
\gexec
SELECT 'CREATE ROLE heymode_production LOGIN' WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'heymode_production')
\gexec
ALTER ROLE heymode_staging WITH LOGIN CREATEDB NOSUPERUSER NOCREATEROLE NOREPLICATION PASSWORD '${pw_s}';
ALTER ROLE heymode_production WITH LOGIN NOCREATEDB NOSUPERUSER NOCREATEROLE NOREPLICATION PASSWORD '${pw_p}';
SELECT 'CREATE DATABASE heymode_staging OWNER heymode_staging ENCODING ''UTF8''' WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'heymode_staging')
\gexec
SELECT 'CREATE DATABASE heymode_production OWNER heymode_production ENCODING ''UTF8''' WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'heymode_production')
\gexec
REVOKE CONNECT ON DATABASE heymode_staging FROM PUBLIC;
REVOKE CONNECT ON DATABASE heymode_production FROM PUBLIC;
SQL
}

# ------------------------------------------------------------------ 6. Redis
step_redis() {
    log "== Redis (127.0.0.1 only + requirepass; staging DB 1 / prefix heymode-staging-, production DB 0 / prefix heymode-production-) =="
    local conf=/etc/redis/redis.conf pw restart=0
    pw="$(secret_get REDIS_PASSWORD)"
    set_conf_line "$conf" '^[[:space:]]*bind[[:space:]]' 'bind 127.0.0.1 -::1';          [ "$WROTE" = 1 ] && restart=1
    set_conf_line "$conf" '^[[:space:]]*protected-mode[[:space:]]' 'protected-mode yes'; [ "$WROTE" = 1 ] && restart=1
    set_conf_line "$conf" '^[[:space:]]*requirepass[[:space:]]' "requirepass $pw";       [ "$WROTE" = 1 ] && restart=1
    run systemctl enable redis-server
    if [ "$restart" = 1 ]; then run systemctl restart redis-server; else run systemctl start redis-server; fi
    if [ "$DRY_RUN" = 0 ]; then
        sleep 1
        if [ "$(REDISCLI_AUTH="$pw" redis-cli ping 2>/dev/null)" = "PONG" ]; then log "Redis answers PONG with the password"; else die "Redis does not answer PONG with the stored password."; fi
        if redis-cli ping 2>&1 | grep -q 'NOAUTH\|Authentication required'; then log "Redis refuses unauthenticated commands"; else die "Redis answers WITHOUT a password."; fi
    fi
}

# ------------------------------------------------------------------ 7. PHP-FPM pool + ini
step_php() {
    log "== PHP-FPM ${PHP_VERSION}: dedicated pool running as $DEPLOY_USER =="
    local restart=0 pool="/etc/php/${PHP_VERSION}/fpm/pool.d"
    write_file "$pool/heymode.conf" 0644 root:root <<POOL
; Managed by deploy/provision.sh. One pool for the app, running as the deploy user: the code, .env (chmod 600) and Horizon
; all belong to $DEPLOY_USER, so php-fpm, artisan and the queue workers read the same files. nginx reaches it through the socket.
[heymode]
user = ${DEPLOY_USER}
group = ${DEPLOY_USER}
listen = ${PHP_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
catch_workers_output = yes
POOL
    [ "$WROTE" = 1 ] && restart=1
    write_file "/etc/php/${PHP_VERSION}/fpm/conf.d/99-heymode.ini" 0644 root:root <<'INI'
; Managed by deploy/provision.sh. validate_timestamps=0 is safe because every deploy reloads php-fpm.
expose_php = Off
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 12M
opcache.enable = 1
opcache.memory_consumption = 192
opcache.validate_timestamps = 0
INI
    [ "$WROTE" = 1 ] && restart=1
    if [ "$DRY_RUN" = 1 ] || [ -f "$pool/www.conf" ]; then
        run mv "$pool/www.conf" "$pool/www.conf.disabled-by-heymode"
        restart=1
    else
        log "default www pool already disabled"
    fi
    run systemctl enable "$PHP_FPM_SERVICE"
    run "php-fpm${PHP_VERSION}" -t
    if [ "$restart" = 1 ]; then run systemctl restart "$PHP_FPM_SERVICE"; else run systemctl start "$PHP_FPM_SERVICE"; fi
}

# ------------------------------------------------------------------ 8. directories
step_dirs() {
    log "== Directories =="
    run install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$STAGING_DIR"
    run install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$PRODUCTION_DIR"
    run install -d -m 755 -o root -g root /var/www/certbot
}

# ------------------------------------------------------------------ 9. nginx (staging, HTTP only until certbot)
step_nginx() {
    log "== nginx staging site (port 80, basic auth, no TLS until certbot) =="
    local pw hash line salt server_name="_"
    [ -z "$STAGING_DOMAIN" ] || server_name="$STAGING_DOMAIN"
    # TLS phase: only when a Let's Encrypt certificate for the domain already exists (certbot certonly --webroot ... comes BEFORE the 2nd run).
    local tls=0 hsts_header=""
    if [ -n "$STAGING_DOMAIN" ] && [ -f "/etc/letsencrypt/live/$STAGING_DOMAIN/fullchain.pem" ]; then
        tls=1
        hsts_header='add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;'
    fi
    log "nginx phase: $([ "$tls" = 1 ] && echo "TLS (certificate found for $STAGING_DOMAIN)" || echo 'HTTP only (no certificate yet)')"
    pw="$(secret_get STAGING_BASIC_AUTH_PASSWORD)"

    # htpasswd (apr1) — only rewritten when the stored hash no longer matches the secret
    if [ "$DRY_RUN" = 1 ]; then
        log "(dry-run) write $HTPASSWD_FILE (user $BASIC_AUTH_USER, apr1 hash of the generated password)"
    else
        line=""; [ -f "$HTPASSWD_FILE" ] && line="$(grep "^$BASIC_AUTH_USER:" "$HTPASSWD_FILE" | head -n1 || true)"
        salt="$(printf '%s' "$line" | awk -F'$' '{print $3}')"
        if [ -n "$salt" ] && [ "$line" = "$BASIC_AUTH_USER:$(printf '%s' "$pw" | openssl passwd -apr1 -salt "$salt" -stdin)" ]; then
            log "unchanged: $HTPASSWD_FILE"
        else
            hash="$(printf '%s' "$pw" | openssl passwd -apr1 -stdin)"
            printf '%s:%s\n' "$BASIC_AUTH_USER" "$hash" > "$HTPASSWD_FILE"
            log "wrote: $HTPASSWD_FILE"
        fi
        chown root:www-data "$HTPASSWD_FILE"; chmod 640 "$HTPASSWD_FILE"
    fi

    printf '# Managed by deploy/provision.sh.\nserver_tokens off;\n' | write_file /etc/nginx/conf.d/00-heymode.conf 0644 root:root
    # The application part is shared by every server block below (HTTP-only phase, the 443 block and the loopback block).
    write_file /etc/nginx/snippets/heymode-staging-app.conf 0644 root:root <<NGINX
# Managed by deploy/provision.sh (P8-03b) — edit the script, not this file.
root ${STAGING_DIR}/public;
index index.php;
charset utf-8;
client_max_body_size 10m;

add_header X-Robots-Tag "noindex, nofollow" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
${hsts_header}
location ^~ /build/ {
    add_header Content-Security-Policy "default-src 'none'; frame-ancestors 'none'" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Robots-Tag "noindex, nofollow" always;
    ${hsts_header}
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files \$uri =404;
}

location ~ /\.(?!well-known/) {
    deny all;
    return 404;
}

location ~* \.(env|log|sql|bak|dump|sh|md|lock|yml|yaml|ini|conf)\$ {
    deny all;
    return 404;
}

location / {
    try_files \$uri \$uri/ /index.php?\$query_string;
}

location = /favicon.ico { access_log off; log_not_found off; }
location = /robots.txt  { access_log off; log_not_found off; }

location = /index.php {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:${PHP_SOCK};
    fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    fastcgi_hide_header X-Powered-By;
    fastcgi_read_timeout 60s;
}

location ~ \.php\$ {
    return 404;
}

error_page 404 /index.php;
NGINX

    local acme_location="    # ACME HTTP-01 must stay reachable without credentials (certbot certonly --webroot -w /var/www/certbot -d <domain>)
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        allow all;
        root /var/www/certbot;
        default_type text/plain;
    }"
    local access_rules="    # Closed: HTTP basic auth for everyone except this server itself (deploy.sh's smoke check calls 127.0.0.1).
    satisfy any;
    allow 127.0.0.1;
    allow ::1;
    deny all;
    auth_basic \"HeyMode staging\";
    auth_basic_user_file ${HTPASSWD_FILE};"

    if [ "$tls" = 1 ]; then
        write_file /etc/nginx/sites-available/heymode-staging 0644 root:root <<NGINX
# Managed by deploy/provision.sh (P8-03b) — TLS phase (certificate for ${STAGING_DOMAIN} exists). Edit the script, not this file.
# 80/<domain>: ACME + redirect.  80/default: loopback only, serves the app so deploy.sh's smoke check (http://127.0.0.1) works.  443: the app.
server {
    listen 80;
    listen [::]:80;
    server_name ${STAGING_DOMAIN};

${acme_location}

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    allow 127.0.0.1;
    allow ::1;
    deny all;

    include snippets/heymode-staging-app.conf;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ${STAGING_DOMAIN};

    ssl_certificate     /etc/letsencrypt/live/${STAGING_DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${STAGING_DOMAIN}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:10m;
    ssl_session_tickets off;

${access_rules}

${acme_location}

    include snippets/heymode-staging-app.conf;
}
NGINX
    else
        write_file /etc/nginx/sites-available/heymode-staging 0644 root:root <<NGINX
# Managed by deploy/provision.sh (P8-03b) — HTTP-only phase (no certificate yet). Edit the script, not this file.
# After DNS points here:  certbot certonly --webroot -w /var/www/certbot -d <domain>  then re-run provision.sh (it adds the 443 block).
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${server_name};

${access_rules}

${acme_location}

    include snippets/heymode-staging-app.conf;
}
NGINX
    fi
    run ln -sfn /etc/nginx/sites-available/heymode-staging /etc/nginx/sites-enabled/heymode-staging
    if [ "$DRY_RUN" = 1 ] || [ -e /etc/nginx/sites-enabled/default ]; then run rm -f /etc/nginx/sites-enabled/default; fi
    run nginx -t
    run systemctl enable nginx
    run systemctl reload-or-restart nginx
}

# ------------------------------------------------------------------ 10. Supervisor (Horizon) + cron (schedule:run)
step_workers() {
    log "== Supervisor (Horizon) + cron (schedule:run); production is written commented out =="
    write_file /etc/supervisor/conf.d/heymode.conf 0644 root:root <<SUPERVISOR
; Managed by deploy/provision.sh. deploy.sh runs 'artisan horizon:terminate'; supervisor restarts Horizon on the new code.
; Until the first deploy there is no app to run: the program ends FATAL after 5 tries. After the first deploy:
;   sudo supervisorctl start horizon-staging
[program:horizon-staging]
command=/usr/bin/php${PHP_VERSION} ${STAGING_DIR}/artisan horizon
directory=${STAGING_DIR}
user=${DEPLOY_USER}
autostart=true
autorestart=true
startsecs=5
startretries=5
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/supervisor/horizon-staging.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5

; PRODUCTION — intentionally disabled until go-live. Uncomment, then: supervisorctl reread && supervisorctl update
;[program:horizon-production]
;command=/usr/bin/php${PHP_VERSION} ${PRODUCTION_DIR}/artisan horizon
;directory=${PRODUCTION_DIR}
;user=${DEPLOY_USER}
;autostart=true
;autorestart=true
;startsecs=5
;startretries=5
;stopasgroup=true
;killasgroup=true
;stopwaitsecs=3600
;redirect_stderr=true
;stdout_logfile=/var/log/supervisor/horizon-production.log
;stdout_logfile_maxbytes=20MB
;stdout_logfile_backups=5
SUPERVISOR
    run systemctl enable supervisor
    run systemctl start supervisor
    run supervisorctl reread
    run supervisorctl update

    write_file /etc/cron.d/heymode 0644 root:root <<CRON
# Managed by deploy/provision.sh. The Laravel scheduler, once a minute, as the deploy user.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
* * * * * ${DEPLOY_USER} cd ${STAGING_DIR} && /usr/bin/php${PHP_VERSION} artisan schedule:run >> /dev/null 2>&1
# PRODUCTION — intentionally disabled until go-live:
#* * * * * ${DEPLOY_USER} cd ${PRODUCTION_DIR} && /usr/bin/php${PHP_VERSION} artisan schedule:run >> /dev/null 2>&1
CRON
}

# ------------------------------------------------------------------ 11. services + summary
step_services() {
    log "== Services =="
    run systemctl enable --now fail2ban
    run systemctl enable unattended-upgrades
    log "ufw is installed but left INACTIVE on purpose: deploy/harden.sh configures the firewall and sshd, after a tested key login."
    if [ "$DRY_RUN" = 0 ]; then
        local s
        for s in nginx "$PHP_FPM_SERVICE" postgresql redis-server supervisor fail2ban cron; do
            if systemctl is-active --quiet "$s"; then log "active: $s"; else die "$s is not active"; fi
        done
        log "Listening sockets:"
        ss -tlnH 2>/dev/null | awk '{print "    " $4}' | sort -u | stream
    fi
}

step_secrets
step_packages
step_composer
step_node
step_swap
step_deploy_user
step_postgres
step_redis
step_php
step_dirs
step_nginx
step_workers
step_services

log "Done$([ "$DRY_RUN" = 1 ] && echo ' (dry-run: nothing was changed)')."
log "Next: add the repo deploy key, clone into ${STAGING_DIR}, then deploy/harden.sh (APP_DIR=${STAGING_DIR} WEB_USER=${DEPLOY_USER}), .env, deploy/deploy.sh staging."
