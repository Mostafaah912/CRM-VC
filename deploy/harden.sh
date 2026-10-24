#!/usr/bin/env bash
# HeyMode Customer OS — P8-01 server hardening (Ubuntu 22.04/24.04). Run as root ON THE VPS.
# Idempotent: safe to re-run. Use --dry-run first: it prints every change and applies nothing.
#
#   sudo ./deploy/harden.sh --dry-run
#   sudo APP_DIR=/var/www/heymode SSH_PORT=22 F2B_IGNOREIP="203.0.113.7" ./deploy/harden.sh
#
# Secrets are never written by this script: the Redis password is read from REDIS_PASSWORD (env) or
# generated and printed ONCE for you to put in .env — it is not stored anywhere else.
set -euo pipefail

DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

APP_DIR="${APP_DIR:-/var/www/heymode}"          # app root; the web root is $APP_DIR/public ONLY
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
SSH_PORT="${SSH_PORT:-22}"
F2B_IGNOREIP="${F2B_IGNOREIP:-}"                 # extra space-separated IPs fail2ban must never ban (your own egress IP)
PG_CONF_DIR="${PG_CONF_DIR:-$(ls -d /etc/postgresql/*/main 2>/dev/null | sort -V | tail -n1 || true)}"
REDIS_CONF="${REDIS_CONF:-/etc/redis/redis.conf}"
WEB_USER="${WEB_USER:-www-data}"
BACKUP_SUFFIX=".pre-harden.$(date +%Y%m%d%H%M%S)"

log()  { printf '\033[1;34m[harden]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[WARN]\033[0m %s\n' "$*" >&2; }
run()  { if [ "$DRY_RUN" = 1 ]; then printf '  (dry-run) %s\n' "$*"; else "$@"; fi; }

if [ "$DRY_RUN" = 0 ] && [ "$(id -u)" -ne 0 ]; then
    echo "Run as root (or use --dry-run)." >&2; exit 1
fi

# Replace-or-append "key value" in a config file; backs the file up once per run.
# Only UNcommented lines are replaced (patterns start at ^[[:space:]]*), so commented examples are left alone.
set_line() { # file, regex-of-existing-line, new-line
    local file="$1" pattern="$2" line="$3"
    if [ "$DRY_RUN" = 1 ]; then printf '  (dry-run) %s: ensure "%s"\n' "$file" "$line"; return; fi
    [ -f "$file" ] || { warn "$file missing — skipped"; return; }
    cp -n "$file" "$file$BACKUP_SUFFIX" || true
    if grep -Eq "$pattern" "$file"; then
        sed -i -E "s|$pattern.*|$line|" "$file"
    else
        printf '%s\n' "$line" >> "$file"
    fi
}

# ---------------------------------------------------------------- packages
log "Packages: ufw, fail2ban, unattended-upgrades"
run apt-get update -qq
run env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ufw fail2ban unattended-upgrades

# ---------------------------------------------------------------- SSH
# Lock-out guard: refuse to disable password auth unless the invoking admin has an authorized key.
log "SSH: key-only, no root login"
# 00- so it is read FIRST: sshd keeps the first value it sees, and cloud-init's 50-cloud-init.conf often says PasswordAuthentication yes.
SSH_DROPIN=/etc/ssh/sshd_config.d/00-heymode-hardening.conf
KEYS_FOUND=0
for home in /root /home/*; do
    [ -s "$home/.ssh/authorized_keys" ] && KEYS_FOUND=1
done
if [ "$DRY_RUN" = 0 ] && [ "$KEYS_FOUND" = 0 ]; then
    echo "REFUSING: no authorized_keys found for any user. Add your public key first, or you will lock yourself out." >&2
    exit 1
fi
warn "Before password login is switched off: in a SECOND terminal, log in with your KEY as a NON-root sudo user and confirm it works."
warn "Keep this session open until you have. Root login is disabled too — you need a sudo user."
if [ "$DRY_RUN" = 0 ] && [ -t 0 ]; then
    read -r -p "Have you tested key login in another terminal? Type 'yes' to continue: " answer
    [ "$answer" = "yes" ] || { echo "Aborted — nothing changed in SSH."; exit 1; }
fi
if [ "$DRY_RUN" = 1 ]; then
    printf '  (dry-run) write %s: PermitRootLogin no, PasswordAuthentication no, KbdInteractiveAuthentication no, PubkeyAuthentication yes, MaxAuthTries 3\n' "$SSH_DROPIN"
else
    cat > "$SSH_DROPIN" <<SSHD
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitEmptyPasswords no
MaxAuthTries 3
X11Forwarding no
SSHD
    sshd -t   # validate BEFORE reloading; set -e aborts on a bad config
    systemctl reload ssh 2>/dev/null || systemctl reload sshd
fi

# ---------------------------------------------------------------- UFW
log "UFW: deny incoming; allow SSH($SSH_PORT), 80, 443"
run ufw default deny incoming
run ufw default allow outgoing
run ufw allow "$SSH_PORT"/tcp
run ufw allow 80/tcp
run ufw allow 443/tcp
run ufw --force enable

# ---------------------------------------------------------------- fail2ban
log "fail2ban: sshd jail"
if [ "$DRY_RUN" = 1 ]; then
    echo "  (dry-run) write /etc/fail2ban/jail.d/heymode.local (sshd enabled, ignoreip 127.0.0.1/8 ::1 ${F2B_IGNOREIP:-<none extra>}, maxretry 5, bantime 1h) and enable the service"
else
    cat > /etc/fail2ban/jail.d/heymode.local <<F2B
[sshd]
enabled = true
ignoreip = 127.0.0.1/8 ::1${F2B_IGNOREIP:+ $F2B_IGNOREIP}
port = $SSH_PORT
maxretry = 5
findtime = 10m
bantime = 1h
F2B
    systemctl enable --now fail2ban
    systemctl restart fail2ban
fi

# ---------------------------------------------------------------- PostgreSQL
log "PostgreSQL: localhost only, scram-sha-256"
if [ -z "$PG_CONF_DIR" ]; then
    warn "PostgreSQL config dir not found (is it installed on this host?) — skipped"
else
    set_line "$PG_CONF_DIR/postgresql.conf" '^[[:space:]]*listen_addresses[[:space:]]*=' "listen_addresses = 'localhost'"
    set_line "$PG_CONF_DIR/postgresql.conf" '^[[:space:]]*password_encryption[[:space:]]*=' "password_encryption = scram-sha-256"
    if [ "$DRY_RUN" = 1 ]; then
        echo "  (dry-run) $PG_CONF_DIR/pg_hba.conf: every host/hostssl 'md5' or 'password' or 'trust' rule -> scram-sha-256"
    else
        cp -n "$PG_CONF_DIR/pg_hba.conf" "$PG_CONF_DIR/pg_hba.conf$BACKUP_SUFFIX" || true
        sed -i -E '/^(host|hostssl|hostnossl)[[:space:]]/ s/[[:space:]](md5|password|trust)([[:space:]]|$)/ scram-sha-256\2/' "$PG_CONF_DIR/pg_hba.conf"
        systemctl restart postgresql
        warn "Existing DB role passwords stored as md5 stay md5 until reset: run  ALTER ROLE <name> PASSWORD '<same password>';  for each (app role, hm_ai_readonly)."
    fi
fi

# ---------------------------------------------------------------- Redis
log "Redis: 127.0.0.1 only + requirepass"
if [ ! -f "$REDIS_CONF" ] && [ "$DRY_RUN" = 0 ]; then
    warn "$REDIS_CONF not found — skipped"
else
    GENERATED=0
    if [ -z "${REDIS_PASSWORD:-}" ]; then
        GENERATED=1
        REDIS_PASSWORD="$(openssl rand -base64 36 | tr -d '/+=\n' | cut -c1-40)"
    fi
    set_line "$REDIS_CONF" '^[[:space:]]*bind[[:space:]]' "bind 127.0.0.1 -::1"
    set_line "$REDIS_CONF" '^[[:space:]]*protected-mode[[:space:]]' "protected-mode yes"
    if [ "$DRY_RUN" = 1 ]; then
        echo "  (dry-run) $REDIS_CONF: requirepass <REDIS_PASSWORD from env, or generated and shown once>"
    else
        set_line "$REDIS_CONF" '^[[:space:]]*requirepass[[:space:]]' "requirepass $REDIS_PASSWORD"
        systemctl restart redis-server 2>/dev/null || systemctl restart redis
        if [ "$GENERATED" = 1 ]; then
            warn "Redis password generated. Put it in $ENV_FILE as REDIS_PASSWORD=... (shown ONCE, not stored):"
            printf '    %s\n' "$REDIS_PASSWORD"
        else
            log "Redis password taken from the REDIS_PASSWORD environment variable."
        fi
        warn "Then update $ENV_FILE and restart Horizon/PHP-FPM, otherwise the app cannot reach Redis."
    fi
fi

# ---------------------------------------------------------------- .env permissions / webroot
log ".env: chmod 600, owned by $WEB_USER, outside the web root"
if [ "$DRY_RUN" = 1 ]; then
    echo "  (dry-run) chown $WEB_USER:$WEB_USER $ENV_FILE; chmod 600 $ENV_FILE"
    echo "  (dry-run) verify the web root is $APP_DIR/public (never $APP_DIR) and that $ENV_FILE is not under it"
elif [ -f "$ENV_FILE" ]; then
    chown "$WEB_USER:$WEB_USER" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    case "$ENV_FILE" in
        "$APP_DIR/public"/*) warn "$ENV_FILE is INSIDE the web root — move it. The web root must be $APP_DIR/public." ;;
    esac
else
    warn "$ENV_FILE not found — skipped (create it, then re-run)"
fi

# ---------------------------------------------------------------- unattended upgrades
log "unattended-upgrades: automatic security updates"
if [ "$DRY_RUN" = 1 ]; then
    echo "  (dry-run) write /etc/apt/apt.conf.d/20auto-upgrades (daily update + unattended upgrade)"
else
    cat > /etc/apt/apt.conf.d/20auto-upgrades <<APT
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
APT
    systemctl enable --now unattended-upgrades
fi

log "Done$([ "$DRY_RUN" = 1 ] && echo ' (dry-run: nothing was changed)'). Now run: deploy/verify-hardening.sh"
