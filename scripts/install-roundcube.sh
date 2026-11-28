#!/usr/bin/env bash
#
# Install Roundcube webmail for cwhpanel ("advanced webmail").
#
# Downloads the pinned release (SHA-256 verified), installs it to
# /usr/share/roundcubemail, provisions the MariaDB database, writes
# config.inc.php (IMAP/SMTP against the local Dovecot/Postfix, Elastic skin,
# archive+zipdownload+cwhpanel_sso plugins), and prepares temp/logs dirs
# outside the web root. The installer/ directory is removed.
#
# SSO: customers jump from the panel with a 60s HMAC token (ROUNDCUBE_SSO_KEY
# in the panel .env, generated here when missing) and are logged in via the
# Dovecot master user (MAIL_MASTER_PASSWORD, required).
#
#   ./scripts/install-roundcube.sh
#
# Requires: curl, tar, a running MariaDB, and the panel .env. Idempotent:
# re-running keeps the database, passwords and keys, refreshes code+config.
# Serve it via nginx at /roundcube/ (see deploy/nginx-panel.conf.example).

set -euo pipefail

RC_VERSION="${RC_VERSION:-1.6.21}"
RC_SHA256="31b42871e7237c2aa30e4bfaab5e943401cc42e8a4948653abe801504855d5e2"
RC_DIR="/usr/share/roundcubemail"
RC_DATA="/var/lib/roundcube"
# The panel pool runs as cwhpanel-panel (dedicated user), NOT nginx. A
# nginx default here writes <config> root:nginx 640, which the pool cannot
# read — phpMyAdmin/Roundcube then render "config is not readable" (and a
# re-run of this installer broke a working one live). Prefer the panel
# group when it exists; nginx stays the fallback for dev installs.
PANEL_GROUP="${PANEL_GROUP:-$(getent group cwhpanel-panel >/dev/null 2>&1 && echo cwhpanel-panel || echo nginx)}"
PANEL_ENV="${PANEL_ENV:-/opt/cwhpanel/.env}"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SRC="${PLUGIN_SRC:-$REPO_DIR/deploy/roundcube/plugins/cwhpanel_sso}"

BASE_URL="https://github.com/roundcube/roundcubemail/releases/download/${RC_VERSION}"
ARCHIVE="roundcubemail-${RC_VERSION}-complete.tar.gz"

log() { printf '==> %s\n' "$*"; }
randhex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

[ -f "$PANEL_ENV" ] || { echo "panel .env not found: $PANEL_ENV (set PANEL_ENV)" >&2; exit 1; }
MASTER_PASS="$(grep -E '^MAIL_MASTER_PASSWORD=' "$PANEL_ENV" | tail -1 | cut -d= -f2-)"
[ -n "$MASTER_PASS" ] || { echo "MAIL_MASTER_PASSWORD is empty in $PANEL_ENV (seed it first)" >&2; exit 1; }

log "Downloading Roundcube ${RC_VERSION}..."
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
curl -fsSL -o "$tmp/$ARCHIVE" "$BASE_URL/$ARCHIVE"
echo "  verifying sha256..."
echo "${RC_SHA256}  ${ARCHIVE}" | (cd "$tmp" && sha256sum -c -) >/dev/null \
    || { echo "sha256 mismatch" >&2; exit 1; }

log "Installing to ${RC_DIR}..."
rm -rf "$RC_DIR"
# NEVER let tar preserve the tarball's numeric ownership: the upstream archive
# is packed by uid/gid 1000, and `tar` as root would hand the whole webmail
# code tree to whichever LOCAL user owns that uid (on a hosting box: the first
# customer account). That is a customer->panel privilege escalation — the
# panel pool executes this PHP. Extract unowned, then pin root ownership.
tar --no-same-owner --no-same-permissions -xzf "$tmp/$ARCHIVE" -C "$tmp"
mv "$tmp/roundcubemail-${RC_VERSION}" "$RC_DIR"
chown -R root:root "$RC_DIR"
chmod -R go-w "$RC_DIR"
rm -rf "$RC_DIR/installer"

log "Installing cwhpanel SSO plugin..."
[ -f "$PLUGIN_SRC/cwhpanel_sso.php" ] || { echo "plugin source missing: $PLUGIN_SRC" >&2; exit 1; }
mkdir -p "$RC_DIR/plugins/cwhpanel_sso"
cp "$PLUGIN_SRC/cwhpanel_sso.php" "$RC_DIR/plugins/cwhpanel_sso/"

# Existing secrets are reused so re-runs never lock out the install.
DB_PASS=""
if [ -f "$RC_DIR/config/config.inc.php" ]; then
    DB_PASS="$(grep -oE "mysql://roundcube:[^@]+@" "$RC_DIR/config/config.inc.php" | head -1 | sed 's#mysql://roundcube:##; s#@$##' || true)"
fi
[ -n "$DB_PASS" ] || DB_PASS="$(randhex 12)"
DES_KEY="$(grep -oE "\\\$config\['des_key'\] = '[^']+'" "$RC_DIR/config/config.inc.php" 2>/dev/null | head -1 | sed "s/.*'//; s/'//" || true)"
[ -n "$DES_KEY" ] || DES_KEY="$(head -c 18 /dev/urandom | base64 | head -c 24)"

if ! grep -qE '^ROUNDCUBE_SSO_KEY=' "$PANEL_ENV"; then
    log "Generating ROUNDCUBE_SSO_KEY in $PANEL_ENV..."
    printf 'ROUNDCUBE_SSO_KEY=%s\n' "$(randhex 16)" >> "$PANEL_ENV"
fi
SSO_KEY="$(grep -E '^ROUNDCUBE_SSO_KEY=' "$PANEL_ENV" | tail -1 | cut -d= -f2-)"

log "Provisioning MariaDB database..."
MYSQL_PWD="${MYSQL_ROOT_PW:-}" mysql -e "CREATE DATABASE IF NOT EXISTS \`roundcube\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
MYSQL_PWD="${MYSQL_ROOT_PW:-}" mysql -e "CREATE USER IF NOT EXISTS 'roundcube'@'localhost' IDENTIFIED BY '${DB_PASS}';"
MYSQL_PWD="${MYSQL_ROOT_PW:-}" mysql -e "ALTER USER 'roundcube'@'localhost' IDENTIFIED BY '${DB_PASS}';"
MYSQL_PWD="${MYSQL_ROOT_PW:-}" mysql -e "GRANT ALL PRIVILEGES ON \`roundcube\`.* TO 'roundcube'@'localhost'; FLUSH PRIVILEGES;"
if [ "$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='roundcube' AND table_name='users';")" = "0" ]; then
    log "Importing initial schema..."
    mysql roundcube < "$RC_DIR/SQL/mysql.initial.sql"
fi

log "Writing config.inc.php..."
cat > "$RC_DIR/config/config.inc.php" <<EOF
<?php

/* Roundcube configuration for cwhpanel. Managed by cwhpanel deployment
 * (scripts/install-roundcube.sh) — local edits are overwritten on re-run. */

\$config['db_dsnw'] = 'mysql://roundcube:${DB_PASS}@localhost/roundcube';
\$config['des_key'] = '${DES_KEY}';

// Local Dovecot IMAP (loopback plaintext, same as the panel's own webmail).
// imap_auth_type stays at auto-detect: picks AUTHENTICATE PLAIN from the
// server's advertised mechanisms (Dovecot enables only 'plain'; forcing
// 'LOGIN' selects the AUTHENTICATE LOGIN mechanism and fails).
\$config['default_host'] = '127.0.0.1';
\$config['default_port'] = 143;

// Local Postfix submission (STARTTLS mandatory, SASL with the login creds).
\$config['smtp_server'] = 'tls://127.0.0.1';
\$config['smtp_port'] = 587;
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';

\$config['skin'] = 'elastic';
\$config['plugins'] = ['archive', 'zipdownload', 'cwhpanel_sso'];

// cwhpanel single sign-on: 60s HMAC tokens issued by the panel, redeemed for
// a Dovecot master-user login (see deploy/roundcube/plugins/cwhpanel_sso/).
\$config['cwhpanel_sso_key'] = '${SSO_KEY}';
\$config['cwhpanel_master_suffix'] = '*master@panel';
\$config['cwhpanel_master_pass'] = '${MASTER_PASS}';

\$config['temp_dir'] = '${RC_DATA}/temp/';
\$config['log_dir'] = '${RC_DATA}/logs/';
\$config['enable_installer'] = false;
EOF
chown "root:${PANEL_GROUP}" "$RC_DIR/config/config.inc.php"
chmod 640 "$RC_DIR/config/config.inc.php"

# The panel FPM pool serves /roundcube; its open_basedir must include the
# Roundcube temp dir or the SSO nonce (single-use token, audit #11) cannot be
# written/consumed by panel requests. Patch the live pool conf idempotently so
# re-running this installer is the one-step remediation on existing hosts.
PANEL_POOL_CONF="${PANEL_POOL_CONF:-/etc/php-fpm-panel.d/panel.conf}"
if [ -f "$PANEL_POOL_CONF" ] && ! grep -q "open_basedir.*${RC_DATA}" "$PANEL_POOL_CONF"; then
    if grep -q "php_admin_value\[open_basedir\]" "$PANEL_POOL_CONF"; then
        log "Adding ${RC_DATA} to the panel pool open_basedir..."
        sed -i "s#php_admin_value\[open_basedir\] = \(.*\)#php_admin_value[open_basedir] = \1:${RC_DATA}#" "$PANEL_POOL_CONF"
        systemctl reload-or-restart php-fpm-panel >/dev/null 2>&1 || true
    fi
fi

log "Preparing data dirs ${RC_DATA}..."
mkdir -p "$RC_DATA/temp" "$RC_DATA/logs"
chown -R "${PANEL_GROUP}:${PANEL_GROUP}" "$RC_DATA"
chmod 750 "$RC_DATA" "$RC_DATA/temp" "$RC_DATA/logs"

# The tarball can carry its own security.selinux xattrs (admin_home_t), which
# httpd_t cannot read; reset to the fcontext labels before serving.
restorecon -R /usr/share/roundcubemail /var/lib/roundcube >/dev/null 2>&1 || true

log "Roundcube installed. Serve it via nginx at /roundcube/ (see deploy/nginx-panel.conf.example)."
