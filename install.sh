#!/usr/bin/env bash
#
# Consolidated cwhpanel installer.
#
# Installs the privileged agent, enables the background worker as a system
# service, and enables the support daemons (crond, ClamAV, vsftpd).
#
# Two modes:
#   production  — panel served by Nginx + PHP-FPM. The agent's peer-credential
#                 allowlist is set from the PHP-FPM pool user (auto-detected).
#   dev         — panel served by `php -S` (runs as root). Allowlist is root-only.
#
# Usage:
#   ./install.sh            # interactive prompt (if a TTY is present)
#   ./install.sh --prod     # production (Nginx + PHP-FPM)
#   ./install.sh --dev      # development (php -S / root)

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Escape a value for a sed replacement (handles &, #, \ and the delimiter).
esc() { printf '%s' "$1" | sed 's/[&#\\]/\\&/g'; }

# ---------------------------------------------------------------------------
# Resolve the PHP-FPM pool uid/gid for the agent allowlist.
# ---------------------------------------------------------------------------
detect_fpm_ids() {
    local conf user group uid gid
    # Prefer a dedicated panel pool; otherwise fall back to any pool with a
    # user/group, then to apache (the Rocky Linux default www pool user).
    conf=""
    for f in /etc/php-fpm-panel.d/*.conf /etc/php-fpm.d/*panel*.conf /etc/php-fpm.d/www.conf /etc/php-fpm.d/*.conf; do
        [ -f "$f" ] || continue
        if grep -qE '^\s*(user|group)\s*=' "$f"; then conf="$f"; break; fi
    done
    [ -z "$conf" ] && conf="/etc/php-fpm.d/www.conf"

    user=$(awk -F'=' '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' "$conf")
    group=$(awk -F'=' '/^[[:space:]]*group[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' "$conf")
    user="${user:-apache}"; group="${group:-apache}"

    uid=$(id -u "$user" 2>/dev/null || echo 0)
    gid=$(id -g "$group" 2>/dev/null || echo 0)
    PANEL_UID="$uid"; PANEL_GID="$gid"
    PANEL_USER="$user"; PANEL_GROUP="$group"
}

# ---------------------------------------------------------------------------
# Pick the mode.
# ---------------------------------------------------------------------------
MODE=""
if [ $# -ge 1 ]; then
    case "$1" in
        --prod|-p) MODE="production" ;;
        --dev|-d)  MODE="dev" ;;
        *) echo "Unknown argument: $1" >&2; echo "Usage: $0 [--prod|--dev]"; exit 2 ;;
    esac
fi

if [ -z "$MODE" ]; then
    if [ -t 0 ]; then
        echo "Select an installation mode:"
        echo "  1) production  — Nginx + PHP-FPM (agent allowlist = your FPM user)"
        echo "  2) development — php -S dev server (agent allowlist = root)"
        printf "Enter 1 or 2 [1]: "
        read -r choice
        case "${choice:-1}" in
            1) MODE="production" ;;
            2) MODE="dev" ;;
            *) echo "Invalid choice; defaulting to production."; MODE="production" ;;
        esac
    else
        MODE="production"
    fi
fi

echo "==> Install mode: $MODE"

if [ "$MODE" = "dev" ]; then
    PANEL_UID=0
    PANEL_GID=0
    echo "    Dev mode: agent will allow peer uid/gid 0 (root, for php -S)."
    ACME_DIR="https://acme-staging-v02.api.letsencrypt.org/directory"
else
    detect_fpm_ids
    echo "    Production mode: PHP-FPM pool user '$PANEL_USER' (uid $PANEL_UID, gid $PANEL_GID)."
    ACME_DIR="https://acme-v02.api.letsencrypt.org/directory"
fi

# Switch the ACME directory: staging for dev, production LE for production.
if [ -f "$here/.env" ]; then
    if grep -q '^ACME_DIRECTORY=' "$here/.env"; then
        sed -i "s#^ACME_DIRECTORY=.*#ACME_DIRECTORY=$(esc "$ACME_DIR")#" "$here/.env"
    else
        echo "ACME_DIRECTORY=$ACME_DIR" >> "$here/.env"
    fi
    echo "    ACME directory set to: $ACME_DIR"
fi

# Session/app encryption key (APP_KEY). Required by Session::sessionKey() —
# an empty key would make session-payload encryption use a public constant.
if [ -f "$here/.env" ] && ! grep -qE '^APP_KEY=.{16,}' "$here/.env"; then
    APP_KEY_NEW="$(head -c 32 /dev/urandom | base64 | tr -d '=+/' | head -c 44)"
    if grep -qE '^APP_KEY=' "$here/.env"; then
        sed -i "s#^APP_KEY=.*#APP_KEY=$APP_KEY_NEW#" "$here/.env"
    else
        echo "APP_KEY=$APP_KEY_NEW" >> "$here/.env"
    fi
    echo "    generated APP_KEY"
fi

# Agent trust-model ticket keys (CWH_PANEL_* = panel mint keys; the agent
# holds the verification copies written into its unit override by
# agent/install.sh). Generated like APP_KEY when absent.
generate_ticket_key() {
    head -c 32 /dev/urandom | base64 | tr -d '=+/' | head -c 44
}
if [ -f "$here/.env" ]; then
    for VAR in CWH_PANEL_ADMIN_KEY CWH_PANEL_CUSTOMER_KEY; do
        if ! grep -qE "^${VAR}=.+" "$here/.env"; then
            NEW_KEY="$(generate_ticket_key)"
            if grep -qE "^${VAR}=" "$here/.env"; then
                sed -i "s#^${VAR}=.*#${VAR}=${NEW_KEY}#" "$here/.env"
            else
                echo "${VAR}=${NEW_KEY}" >> "$here/.env"
            fi
            echo "    generated $VAR"
        fi
    done
fi

echo
echo "==> 1/4 Install privileged agent..."
# Runtime bundles ship a prebuilt agent (agent/install-packaged.sh); source
# checkouts use the cargo installer. Prefer the packaged one so a release
# tree installs without Rust/Cargo.
if [ -x "$here/agent/install-packaged.sh" ] && [ -x "$here/agent/cwhpanel-agent" ]; then
    bash "$here/agent/install-packaged.sh" "$PANEL_UID" "$PANEL_GID"
else
    bash "$here/agent/install.sh" "$PANEL_UID" "$PANEL_GID"
fi

echo
echo "==> 2/4 Install background worker service..."
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-worker.service" > /etc/systemd/system/cwhpanel-worker.service
systemctl daemon-reload
systemctl enable cwhpanel-worker.service >/dev/null 2>&1 || true

echo
echo "==> 2b/4 Install boot-restore service (transient workers/apps)..."
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-boot-restore.service" > /etc/systemd/system/cwhpanel-boot-restore.service
systemctl daemon-reload
systemctl enable cwhpanel-boot-restore.service >/dev/null 2>&1 || true

echo
echo "==> 3/4 Enable support daemons (crond, ClamAV, vsftpd, mail)..."
systemctl enable --now crond >/dev/null 2>&1 || true
if command -v freshclam >/dev/null 2>&1; then
    systemctl enable --now clamd@scan >/dev/null 2>&1 || true
    systemctl enable --now clamav-freshclam >/dev/null 2>&1 || true
fi
if command -v vsftpd >/dev/null 2>&1; then
    systemctl enable vsftpd >/dev/null 2>&1 || true
fi
# Mail: policy rate-limiter daemon.
DB_HOST_V=$(grep -E '^DB_HOST=' "$here/.env" 2>/dev/null | cut -d= -f2- || true)
DB_PORT_V=$(grep -E '^DB_PORT=' "$here/.env" 2>/dev/null | cut -d= -f2- || true)
DB_DATABASE_V=$(grep -E '^DB_DATABASE=' "$here/.env" 2>/dev/null | cut -d= -f2- || true)
DB_USERNAME_V=$(grep -E '^DB_USERNAME=' "$here/.env" 2>/dev/null | cut -d= -f2- || true)
DB_PASSWORD_V=$(grep -E '^DB_PASSWORD=' "$here/.env" 2>/dev/null | cut -d= -f2- || true)
# DB credentials go into a 0640 root:root EnvironmentFile — never inline in
# the unit (world-readable, and readable by any hosting customer). Written
# directly (no sed substitution) so a password containing # or & is safe.
install -d -m 0755 /etc/cwhpanel
umask 077
cat > /etc/cwhpanel/mail-policy.env <<EOF
DB_HOST=${DB_HOST_V:-127.0.0.1}
DB_PORT=${DB_PORT_V:-3306}
DB_DATABASE=${DB_DATABASE_V:-cwhpanel}
DB_USERNAME=${DB_USERNAME_V:-root}
DB_PASSWORD=${DB_PASSWORD_V:-}
EOF
chmod 0640 /etc/cwhpanel/mail-policy.env
chown root:root /etc/cwhpanel/mail-policy.env
sed -e "s#__PANEL_DIR__#$here#g" \
    "$here/deploy/cwhpanel-mail-policy.service" > /etc/systemd/system/cwhpanel-mail-policy.service
chmod 644 /etc/systemd/system/cwhpanel-mail-policy.service
chmod 755 "$here/agent/mail-policy.py"
umask 022
# M-13: local-submission envelope-sender wrapper (forces a hosting account's
# PHP mail()/cron/shell envelope sender to an address it owns) + submission-only
# sender-login catch-all (587/465); inbound :25 stays on the exact-match map.
install -m 0644 "$here/deploy/cwhpanel-modsec-logrotate" /etc/logrotate.d/cwhpanel-modsec
install -m 0644 "$here/deploy/cwhpanel-tmp.sh" /etc/profile.d/cwhpanel-tmp.sh
install -m 0755 -o root -g root "$here/deploy/cwhpanel-sendmail" /usr/local/sbin/cwhpanel-sendmail
ln -sf /usr/local/sbin/cwhpanel-sendmail /usr/sbin/sendmail
bash "$here/scripts/postfix-submission-sender.sh" >/dev/null 2>&1 || true
# L-14: per-account application network namespaces (script + host NAT/rules).
install -m 0755 -o root -g root "$here/deploy/cwhpanel-netns" /usr/local/sbin/cwhpanel-netns
/usr/local/sbin/cwhpanel-netns host-setup >/dev/null 2>&1 || true
systemctl enable cwhpanel-mail-policy.service >/dev/null 2>&1 || true

# Cron run-capture wrapper (panel cron jobs run through it so exit/output/
# duration land in ~/.cwhpanel-cron/<jobid>.json for the list pages).
cp "$here/agent/cwhpanel-cron-run.sh" /usr/local/bin/cwhpanel-cron-run
chmod 755 /usr/local/bin/cwhpanel-cron-run

# Unattended-install helpers (root-owned host scripts run as the account
# user by the agent's installer.*.install ops: WordPress, DokuWiki, Matomo).
install -d -m 0755 /usr/local/share/cwhpanel
install -m 0644 "$here/agent/wp-install.php" /usr/local/share/cwhpanel/wp-install.php
install -m 0644 "$here/agent/dw-install.php" /usr/local/share/cwhpanel/dw-install.php
install -m 0644 "$here/agent/mt-install.php" /usr/local/share/cwhpanel/mt-install.php
install -m 0644 "$here/agent/drupal-install.php" /usr/local/share/cwhpanel/drupal-install.php
install -m 0644 "$here/agent/piwigo-install.php" /usr/local/share/cwhpanel/piwigo-install.php

# SSL auto-renewal cron (daily ACME sweep). Installed idempotently.
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-renew.cron" > /etc/cron.d/cwhpanel-renew
chmod 644 /etc/cron.d/cwhpanel-renew

# App-catalog freshness cron (weekly upstream check + auto-update of
# version-templated rows). Installed idempotently.
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-appcatalog.cron" > /etc/cron.d/cwhpanel-appcatalog
chmod 644 /etc/cron.d/cwhpanel-appcatalog

# Scheduled-backup dispatcher cron (daily enqueue of due backup_schedules
# into `backup.scheduled` worker jobs). Installed idempotently; the file was
# previously referenced by docs + uninstall.sh but never created here, so
# schedules silently never fired on fresh installs.
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-backup.cron" > /etc/cron.d/cwhpanel-backup
chmod 644 /etc/cron.d/cwhpanel-backup

# Fleet backup plan scheduler (all accounts, every 10 min; the script checks
# enabled + next_run_at). Idempotent.
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-backup-plan.cron" > /etc/cron.d/cwhpanel-backup-plan
chmod 644 /etc/cron.d/cwhpanel-backup-plan

# FTP quota sweep cron (daily usage check, suspends over-quota FTP logins).
# Installed idempotently.
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-ftp-quota.cron" > /etc/cron.d/cwhpanel-ftp-quota
chmod 644 /etc/cron.d/cwhpanel-ftp-quota

# Package-limit enforcement sweeps. These used to run as Watchdog checks; the
# free edition drops Watchdog, so schedule them directly.
# Bandwidth: daily month-to-date accounting, warnings + suspension at 100%.
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-bandwidth.cron" > /etc/cron.d/cwhpanel-bandwidth
chmod 644 /etc/cron.d/cwhpanel-bandwidth
# MySQL governor: per-minute kill of statements over mysql_max_statement_sec.
sed "s#__PANEL_DIR__#$(esc "$here")#g" "$here/deploy/cwhpanel-mysql-governor.cron" > /etc/cron.d/cwhpanel-mysql-governor
chmod 644 /etc/cron.d/cwhpanel-mysql-governor

# cron.d files MUST be newline-terminated (crond silently drops an entry whose
# file lacks the trailing newline — bitten live: the vulnscan/renew/botshield
# jobs never fired). Tolerates templates that forget the final newline.
for f in /etc/cron.d/cwhpanel-*; do
    [ -f "$f" ] && [ -n "$(tail -c 1 "$f")" ] && printf '\n' >> "$f"
done

echo
echo "==> 3c/4 System hardening (PHP version disclosure, backup perms)..."
# Hide the exact PHP version from responses (all installed PHP trees).
for ini in /etc/php.ini /etc/opt/remi/php*/php.ini; do
    [ -f "$ini" ] || continue
    sed -i -E 's/^;?expose_php[[:space:]]*=.*/expose_php = Off/' "$ini"
done
# Full-home archives + DB dumps must not be world-readable/traversable.
install -d -m 0700 -o root -g root /var/backups

echo
echo "==> 4/4 Start agent + worker + policy daemon..."
systemctl start cwhpanel-agent.service 2>/dev/null || true
systemctl start cwhpanel-worker.service 2>/dev/null || true
systemctl start cwhpanel-mail-policy.service 2>/dev/null || true

echo
echo "Installation complete ($MODE)."
echo
echo "Services:"
echo "  cwhpanel-agent.service          (privileged provisioning agent)"
echo "  cwhpanel-worker.service         (background job/webhook runner)"
echo "  cwhpanel-mail-policy.service    (outbound mail rate limiter)"
echo
if [ "$MODE" = "dev" ]; then
    echo "Start the dev server:"
    echo "  php -S 0.0.0.0:8080 -t public public/router.php"
else
    echo "Serve with Nginx + PHP-FPM — see deploy/nginx-panel.conf.example"
    echo "and deploy/php-fpm-panel.conf.example."
fi
echo "Panels are split: customer login on the public port, admin on its own"
echo "TLS port (PANEL_ADMIN_PORT, default 8443) — see deploy/cwhpanel-admin-port.conf"
echo "for the nginx listen lines + firewalld port."
echo
echo "Verify with:"
echo "  systemctl status cwhpanel-agent cwhpanel-worker"