#!/usr/bin/env bash
# HeyMode Customer OS — P8-01 hardening checklist verifier. Read-only: changes nothing.
#
#   sudo APP_DIR=/var/www/heymode APP_URL=https://crm.example.com ./deploy/verify-hardening.sh
#
# Prints PASS/FAIL/SKIP per check; exits 1 if any FAIL. Needs root for ufw/sshd -T/ss -p.
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/heymode}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
APP_URL="${APP_URL:-}"
SSH_PORT="${SSH_PORT:-22}"
REDIS_PORT="${REDIS_PORT:-6379}"
PG_PORT="${PG_PORT:-5432}"

FAILS=0
pass() { printf '\033[1;32mPASS\033[0m  %s\n' "$1"; }
fail() { printf '\033[1;31mFAIL\033[0m  %s\n' "$1"; FAILS=$((FAILS + 1)); }
skip() { printf '\033[1;33mSKIP\033[0m  %s\n' "$1"; }
check() { # description, command...
    local desc="$1"; shift
    if "$@" >/dev/null 2>&1; then pass "$desc"; else fail "$desc"; fi
}

[ "$(id -u)" -eq 0 ] || echo "(not root: ufw/sshd/ss results may be incomplete)"

echo "== Firewall =="
if command -v ufw >/dev/null; then
    UFW="$(ufw status 2>/dev/null)"
    echo "$UFW" | grep -q '^Status: active' && pass "UFW active" || fail "UFW active"
    echo "$UFW" | grep -Eq "^(80|80/tcp)[[:space:]]+ALLOW" && pass "UFW allows 80" || fail "UFW allows 80"
    echo "$UFW" | grep -Eq "^(443|443/tcp)[[:space:]]+ALLOW" && pass "UFW allows 443" || fail "UFW allows 443"
    # Anything allowed besides 80/443/SSH is a finding.
    EXTRA="$(echo "$UFW" | grep -E 'ALLOW' | grep -Ev "^(80|443|$SSH_PORT)(/tcp)?[[:space:]]|\(v6\)|OpenSSH" || true)"
    [ -z "$EXTRA" ] && pass "UFW opens nothing besides 80/443/SSH" || fail "UFW has extra allow rules: $EXTRA"
else
    fail "ufw installed"
fi

echo "== SSH (sshd -T) =="
if SSHD="$(sshd -T 2>/dev/null)" && [ -n "$SSHD" ]; then
    echo "$SSHD" | grep -qi '^passwordauthentication no' && pass "SSH password auth off" || fail "SSH password auth off"
    echo "$SSHD" | grep -qi '^permitrootlogin no' && pass "SSH root login off" || fail "SSH root login off"
    echo "$SSHD" | grep -qi '^pubkeyauthentication yes' && pass "SSH key auth on" || fail "SSH key auth on"
else
    skip "sshd -T unavailable (run as root)"
fi

echo "== fail2ban / updates =="
check "fail2ban service active" systemctl is-active --quiet fail2ban
fail2ban-client status sshd 2>/dev/null | grep -q 'Status for the jail' && pass "fail2ban sshd jail running" || fail "fail2ban sshd jail running"
check "unattended-upgrades enabled" systemctl is-enabled --quiet unattended-upgrades

echo "== Listening ports (ss -tlnp) =="
LISTEN="$(ss -tlnH 2>/dev/null)"
public_listener() { echo "$LISTEN" | awk -v p=":$1\$" '$4 ~ p {print $4}' | grep -Ev '^(127\.0\.0\.1|\[::1\]|::1)' ; }
if [ -z "$LISTEN" ]; then
    skip "ss unavailable"
else
    echo "$LISTEN" | awk -v p=":$PG_PORT\$" '$4 ~ p' | grep -q . || skip "PostgreSQL not listening on $PG_PORT (different host/port?)"
    [ -z "$(public_listener "$PG_PORT")" ] && pass "PostgreSQL not exposed beyond localhost" || fail "PostgreSQL listens on a public address: $(public_listener "$PG_PORT" | tr '\n' ' ')"
    [ -z "$(public_listener "$REDIS_PORT")" ] && pass "Redis not exposed beyond localhost" || fail "Redis listens on a public address: $(public_listener "$REDIS_PORT" | tr '\n' ' ')"
fi
if command -v psql >/dev/null && su postgres -c "psql -Atc 'show password_encryption'" 2>/dev/null | grep -q scram-sha-256; then
    pass "PostgreSQL password_encryption = scram-sha-256"
else
    skip "PostgreSQL password_encryption not verifiable here"
fi
if command -v redis-cli >/dev/null; then
    if redis-cli -p "$REDIS_PORT" ping 2>&1 | grep -q 'NOAUTH\|Authentication required'; then
        pass "Redis requires a password"
    else
        fail "Redis answers without a password"
    fi
fi

echo "== .env =="
if [ -f "$ENV_FILE" ]; then
    PERM="$(stat -c '%a' "$ENV_FILE" 2>/dev/null || stat -f '%Lp' "$ENV_FILE")"
    [ "$PERM" = "600" ] && pass ".env is chmod 600 (is $PERM)" || fail ".env is chmod 600 (is $PERM)"
    case "$ENV_FILE" in "$APP_DIR/public"/*) fail ".env is outside the web root" ;; *) pass ".env is outside the web root" ;; esac
    grep -Eq '^APP_DEBUG=false[[:space:]]*$' "$ENV_FILE" && pass "APP_DEBUG=false" || fail "APP_DEBUG=false"
    grep -Eq '^APP_ENV=production[[:space:]]*$' "$ENV_FILE" && pass "APP_ENV=production" || fail "APP_ENV=production"
    git -C "$APP_DIR" ls-files --error-unmatch .env >/dev/null 2>&1 && fail ".env is tracked by Git" || pass ".env is not tracked by Git"
else
    fail ".env exists at $ENV_FILE"
fi

echo "== HTTPS headers (curl -I) =="
if [ -z "$APP_URL" ]; then
    skip "APP_URL not set — pass APP_URL=https://your.domain to check headers"
else
    HOST="${APP_URL#*://}"; HOST="${HOST%%/*}"
    HEAD="$(curl -sSI --max-time 15 "$APP_URL/login" 2>&1)" || HEAD=""
    hdr() { echo "$HEAD" | tr -d '\r' | grep -i "^$1:" ; }
    [ -n "$HEAD" ] && pass "HTTPS reachable" || fail "HTTPS reachable"
    hdr strict-transport-security | grep -qi 'max-age=' && pass "HSTS present" || fail "HSTS present"
    hdr content-security-policy | grep -q . && pass "CSP present" || fail "CSP present"
    hdr x-frame-options | grep -qi 'deny\|sameorigin' && pass "X-Frame-Options present" || fail "X-Frame-Options present"
    hdr x-content-type-options | grep -qi nosniff && pass "X-Content-Type-Options: nosniff" || fail "X-Content-Type-Options: nosniff"
    hdr referrer-policy | grep -q . && pass "Referrer-Policy present" || fail "Referrer-Policy present"
    hdr server | grep -Eqi '[0-9]+\.[0-9]+' && fail "Server header hides the version ($(hdr server))" || pass "Server header hides the version"
    HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://$HOST/login" || true)"
    [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "308" ] && pass "HTTP redirects to HTTPS" || fail "HTTP redirects to HTTPS (got $HTTP_CODE)"
    DOT="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APP_URL/.env" || true)"
    [ "$DOT" = "403" ] || [ "$DOT" = "404" ] && pass "/.env is blocked ($DOT)" || fail "/.env is blocked (got $DOT)"
    HZN="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$APP_URL/horizon" || true)"
    [ "$HZN" = "403" ] || [ "$HZN" = "302" ] && pass "/horizon refuses anonymous visitors ($HZN)" || fail "/horizon refuses anonymous visitors (got $HZN)"
fi

echo
if [ "$FAILS" -eq 0 ]; then printf '\033[1;32mALL CHECKS PASSED\033[0m\n'; else printf '\033[1;31m%d CHECK(S) FAILED\033[0m\n' "$FAILS"; exit 1; fi
