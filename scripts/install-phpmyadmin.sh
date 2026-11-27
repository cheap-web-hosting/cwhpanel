#!/usr/bin/env bash
#
# Install phpMyAdmin for cwhpanel.
#
# Downloads the pinned release (SHA-256 verified), installs it to
# /usr/share/phpMyAdmin, writes a config.inc.php (cookie auth against the local
# MariaDB socket) with a freshly generated blowfish secret, and prepares the
# session/temp directory.
#
#   ./scripts/install-phpmyadmin.sh
#
# Requires: curl, unzip, and a running MariaDB (for the socket path detection).
# Idempotent: re-running refreshes the config but keeps the install current.

set -euo pipefail

PMA_VERSION="${PMA_VERSION:-5.2.3}"
PMA_SHA256="2d2e13c735366d318425c78e4ee2cc8fc648d77faba3ddea2cd516e43885733f"
PMA_DIR="/usr/share/phpMyAdmin"
PMA_TMP="/var/lib/phpmyadmin/tmp"
# The panel pool runs as cwhpanel-panel (dedicated user), NOT nginx. A
# nginx default here writes <config> root:nginx 640, which the pool cannot
# read — phpMyAdmin/Roundcube then render "config is not readable" (and a
# re-run of this installer broke a working one live). Prefer the panel
# group when it exists; nginx stays the fallback for dev installs.
PANEL_GROUP="${PANEL_GROUP:-$(getent group cwhpanel-panel >/dev/null 2>&1 && echo cwhpanel-panel || echo nginx)}"

BASE_URL="https://files.phpmyadmin.net/phpMyAdmin/${PMA_VERSION}"
ARCHIVE="phpMyAdmin-${PMA_VERSION}-all-languages.zip"

log() { printf '==> %s\n' "$*"; }

log "Downloading phpMyAdmin ${PMA_VERSION}..."
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -fsSL -o "$tmp/$ARCHIVE" "$BASE_URL/$ARCHIVE"
echo "  verifying sha256..."
echo "${PMA_SHA256}  ${ARCHIVE}" | (cd "$tmp" && sha256sum -c -) >/dev/null \
    || { echo "sha256 mismatch" >&2; exit 1; }

log "Installing to ${PMA_DIR}..."
rm -rf "$PMA_DIR"
unzip -q "$tmp/$ARCHIVE" -d "$tmp"
mv "$tmp/phpMyAdmin-${PMA_VERSION}-all-languages" "$PMA_DIR"

log "Writing config.inc.php..."
SECRET="$(head -c 32 /dev/urandom | base64 | tr -d '=+/' | head -c 32)"
cat > "$PMA_DIR/config.inc.php" <<EOF
<?php
declare(strict_types=1);

/* phpMyAdmin configuration for cwhpanel. Managed by cwhpanel deployment. */

\$cfg['blowfish_secret'] = '${SECRET}';

\$i = 0;
\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = 'localhost';
\$cfg['Servers'][\$i]['compress'] = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
\$cfg['Servers'][\$i]['AllowRoot'] = false;
\$cfg['Servers'][\$i]['hide_db'] = '^(mysql|information_schema|performance_schema|sys|phpmyadmin)\$';

\$cfg['TempDir'] = '${PMA_TMP}';
\$cfg['UploadDir'] = '';
\$cfg['SaveDir'] = '';

\$cfg['CheckConfigurationPermissions'] = false;
\$cfg['PmaNoRelation_DisableWarning'] = true;
EOF
chown "root:${PANEL_GROUP}" "$PMA_DIR/config.inc.php"
chmod 640 "$PMA_DIR/config.inc.php"

log "Preparing temp dir ${PMA_TMP}..."
mkdir -p "$PMA_TMP"
chown -R "${PANEL_GROUP}:${PANEL_GROUP}" "$(dirname "$PMA_TMP")"
chmod 750 "$(dirname "$PMA_TMP")"

# The zip can carry security.selinux xattrs; httpd_t needs the fcontext label.
restorecon -R "$PMA_DIR" /var/lib/phpmyadmin >/dev/null 2>&1 || true

log "phpMyAdmin installed. Serve it via nginx at /phpmyadmin/ (see deploy/nginx-panel.conf.example)."
