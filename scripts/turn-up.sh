#!/usr/bin/env bash
#
# cwhpanel clean-host turn-up playbook.
#
# Stands up the full hosting stack the panel relies on for real (non dry-run)
# provisioning on a fresh Rocky Linux 9 box:
#
#   1. DNF packages        (nginx, php-fpm + extensions, mariadb, postfix, dovecot,
#                           powerdns + gmysql, clamav, vsftpd, git,
#                           nodejs, python, SELinux tooling)
#   2. MariaDB             (cwhpanel + pdns gmysql databases, users/grants, schemas)
#   3. PowerDNS            (gmysql backend, authoritative on 127.0.0.1)
#   4. Mail                (postfix + dovecot; agent `mail.setup`/
#                           `mail.smtp.setup` runs the postconf/dovecot details)
#   5. SELinux MCS policy  (build + load cwhpanel module, fcontext overrides, booleans)
#   6. Panel services      (agent, worker, mail-policy) via install.sh
#   7. DB schema           (scripts/install.php: create db + migrations + seed)
#
# Usage:
#   ./scripts/turn-up.sh                             # interactive (asks for a DB root password if needed,
#                                                   # and for the panel domain)
#   ./scripts/turn-up.sh --non-interactive           # all defaults (panel on server hostname or IP)
#   ./scripts/turn-up.sh --panel-domain=panel.ex.com # serve the panel on this domain (+ APP_URL)
#
# Idempotent: safe to re-run. Requires root.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

NONINTERACTIVE=0
PANEL_DOMAIN=""
for arg in "$@"; do
    case "$arg" in
        --non-interactive) NONINTERACTIVE=1 ;;
        --panel-domain=*) PANEL_DOMAIN="${arg#*=}" ;;
        --help|-h) sed -n '2,24p' "$0"; exit 0 ;;
        *) die "unknown argument: $arg (see --help)" ;;
    esac
done

log()  { printf '\n==> %s\n' "$*"; }
step() { printf '    - %s\n' "$*"; }
warn() { printf '    ! WARN: %s\n' "$*"; }
die()  { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "must run as root"

# Read .env values (with defaults) from the panel .env if present.
env_get() {
    local key="$1" default="${2:-}"
    local v
    v="$(grep -E "^${key}=" "$here/.env" 2>/dev/null | head -1 | cut -d= -f2- || true)"
    printf '%s' "${v:-$default}"
}
DB_HOST=$(env_get DB_HOST 127.0.0.1)
DB_PORT=$(env_get DB_PORT 3306)
DB_DATABASE=$(env_get DB_DATABASE cwhpanel)
DB_USERNAME=$(env_get DB_USERNAME cwhpanel)
# Never use MariaDB root for the panel: an older .env.example pinned
# "root" and CREATE USER IF NOT EXISTS does not repoint the existing
# unix_socket account, so scripts/install.php (TCP + password) failed with
# "Access denied for user root@localhost" and turn-up aborted before the
# panel FPM pool was installed.
if [ "$DB_USERNAME" = "root" ] || [ -z "$DB_USERNAME" ]; then DB_USERNAME=cwhpanel; fi
DB_PASSWORD=$(env_get DB_PASSWORD "$(head -c 24 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 24)")
PDNS_DB="${PDNS_DB:-pdns}"
PDNS_USER="${PDNS_USER:-pdns}"
# Reuse the password PowerDNS is already configured with, so a re-run does
# not rotate it behind a running daemon (the panel never reads this secret).
PDNS_PASSWORD="${PDNS_PASSWORD:-$(sed -n 's/^gmysql-password=//p' /etc/pdns/pdns.conf 2>/dev/null | head -1 || true)}"
PDNS_PASSWORD="${PDNS_PASSWORD:-$(head -c 24 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 24)}"

# --- Panel env must exist before services are installed: install.sh reads the
# DB credentials to render the mail-policy EnvironmentFile and the worker reads
# the same .env. On a fresh host .env did not exist yet (it was written after
# the services step) and `set -euo pipefail` killed install.sh on the grep.
if [ ! -f "$here/.env" ]; then
    cp -a "$here/.env.example" "$here/.env"
    step "seeded .env from .env.example"

fi
for kv in "DB_HOST=$DB_HOST" "DB_PORT=$DB_PORT" "DB_DATABASE=$DB_DATABASE" \
          "DB_USERNAME=$DB_USERNAME" "DB_PASSWORD=$DB_PASSWORD"; do
    key="${kv%%=*}"; val="${kv#*=}"
    if grep -qE "^${key}=" "$here/.env"; then
        sed -i "s#^${key}=.*#${key}=${val}#" "$here/.env"
    else
        echo "${key}=${val}" >> "$here/.env"
    fi
done


# ---------------------------------------------------------------------------
log "1/7 DNF packages"
# NOTE (el9): there is no dovecot-lmtp (LMTP ships in base dovecot), no
# clamav-update (freshclam is clamav-freshclam), no php-curl (built into
# php-common), and zip is php-pecl-zip on the Remi modular streams.
dnf -y install epel-release >/dev/null
# MariaDB 10.11 LTS (AppStream module, pinned — NOT the rolling default):
# Drupal 11 needs >= 10.6 and Mautic 7 needs >= 10.11, while the old 10.5
# stream cannot run either. The module must be enabled before the package
# install below so fresh boxes converge on the same stream as upgrades.
dnf module reset mariadb -y >/dev/null 2>&1 || true
dnf module enable mariadb:10.11 -y >/dev/null 2>&1 || true
# Remi + CRB up front (not in the multi-PHP section below): the main package
# set needs them — php-imap and php-pecl-imagick-im6 only exist in the
# php:remi-* module streams, and without an enabled stream the whole step-1
# transaction fails with "Unable to find a match" (bitten on a fresh 9.9 box).
rpm -q remi-release >/dev/null 2>&1 || dnf -y install "https://rpms.remirepo.net/enterprise/remi-release-9.rpm" >/dev/null 2>&1 || true
dnf -y install dnf-plugins-core >/dev/null 2>&1 || true
dnf config-manager --set-enabled crb remi-safe >/dev/null 2>&1 || true
# System PHP 8.4 (Remi modular stream, pinned — NOT the rolling default).
# This is the panel's system PHP ("84"); multi-PHP SCL versions ride alongside.
dnf module reset php -y >/dev/null 2>&1 || true
dnf module enable php:remi-8.4 -y >/dev/null 2>&1 || true
dnf -y --nobest install \
    nginx \
    php php-fpm php-cli php-mysqlnd php-gd php-intl php-mbstring \
    php-xml php-pecl-zip php-process php-opcache php-imap \
    php-bcmath php-enchant php-gmp php-ldap php-pgsql php-snmp php-soap \
    php-sodium php-tidy php-pecl-apcu php-pecl-imagick-im6 php-pecl-memcached \
    php-pecl-yaml \
    mariadb-server mariadb \
    postfix dovecot dovecot-mysql dovecot-pigeonhole \
    pdns pdns-backend-mysql \
    clamav clamav-freshclam clamd vsftpd \
    git nodejs python3 rsync \
    nginx-mod-modsecurity mod_security_crs \
    policycoreutils-python-utils policycoreutils-devel setools-console \
    jq curl openssl unzip firewalld nftables whois quota >/dev/null
step "packages installed"

# Multi-PHP: Remi SCL versions 74/80/81/82/83 (all enabled for boot; the panel
# can install/uninstall more from Server → PHP Versions later). Best-effort:
# a slow/mirror-less network must not fail the whole turn-up.
if command -v dnf >/dev/null 2>&1; then
    rpm -q remi-release >/dev/null 2>&1 || dnf -y install "https://rpms.remirepo.net/enterprise/remi-release-9.rpm" >/dev/null 2>&1 || true
    for v in 74 80 81 82 83; do
        if ! rpm -q "php${v}-php-fpm" >/dev/null 2>&1; then
            for pkg in php-fpm php-cli php-common php-mysqlnd php-gd php-mbstring php-xml php-opcache php-intl; do
                dnf -y install "php${v}-${pkg}" >/dev/null 2>&1 || true
            done
        fi
        systemctl enable --now "php${v}-php-fpm" >/dev/null 2>&1 || true
    done
    step "multi-PHP (Remi 74/80/81/82/83) installed + enabled"
fi

# WAF: OWASP CRS loaded once at nginx http level (per-site engine toggles
# stay in /etc/modsecurity/<domain>.conf, managed by the panel).
install -d -m 0755 /etc/modsecurity
cp "$here/deploy/modsecurity-crs.conf" /etc/modsecurity/cwhpanel-crs.conf
# Drop SHA-1 SSH MACs. 00- prefix is required: 50-redhat.conf pulls in
# /etc/crypto-policies/back-ends/opensshserver.config (which re-adds
# hmac-sha1) and sshd takes the FIRST value it sees.
if [ -f "$here/deploy/sshd-00-cwhpanel-macs.conf" ]; then
    install -d -m 0755 /etc/ssh/sshd_config.d
    install -m 0600 "$here/deploy/sshd-00-cwhpanel-macs.conf" /etc/ssh/sshd_config.d/00-cwhpanel-macs.conf
    if sshd -t 2>/dev/null; then
        systemctl reload sshd >/dev/null 2>&1 || true
        step "sshd: SHA-1 MACs disabled"
    else
        rm -f /etc/ssh/sshd_config.d/00-cwhpanel-macs.conf
        warn "sshd -t rejected the MAC drop-in; removed it (SSH left untouched)"
    fi
fi

cp "$here/deploy/cwhpanel-modsecurity.conf" /etc/nginx/conf.d/cwhpanel-modsecurity.conf
nginx -t 2>/dev/null && step "WAF (OWASP CRS) wired" || step "WARNING: WAF config test failed"

# ---------------------------------------------------------------------------
log "2/7 MariaDB"
systemctl daemon-reload >/dev/null 2>&1 || true
systemctl enable --now mariadb >/dev/null 2>&1 || true

# Secure default to match the remote gate (default OFF): loopback-only
# listener until the admin explicitly enables Remote MySQL (which removes
# this drop-in via the coupled mysql.bind.write op). Without it a fresh box
# binds 0.0.0.0:3306 with only the firewall in front.
printf '[mysqld]\nbind-address=127.0.0.1\n' > /etc/my.cnf.d/cwhpanel-bind.cnf 2>/dev/null \
    && systemctl restart mariadb >/dev/null 2>&1 \
    && step "MariaDB loopback-bound (Remote MySQL gate stays OFF)" \
    || warn "could not loopback-bind MariaDB"

# Deliberately NOT binding to 127.0.0.1: Remote MySQL is a supported feature
# (admin → Firewall → Remote MySQL opens 3306 for per-host grants). Exposure
# is controlled by the mysql.remote_enabled gate (default OFF) + firewalld,
# not by the listener. Do not add bind-address here without breaking it.

# Root access: prefer unix_socket (Rocky default). Try socket, fall back to a
# prompted root password only if socket auth is unavailable.
mysql_root() {
    if mysql -N -e 'SELECT 1' >/dev/null 2>&1; then
        mysql "$@"
    elif [ "$NONINTERACTIVE" = "1" ]; then
        mysql -uroot "$@"
    else
        read -rsp "MariaDB root password: " MYSQL_ROOT_PW; echo
        MYSQL_PWD="$MYSQL_ROOT_PW" mysql -uroot "$@"
    fi
}

# Panel database.
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql_root -e "CREATE USER IF NOT EXISTS '$DB_USERNAME'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
mysql_root -e "CREATE USER IF NOT EXISTS '$DB_USERNAME'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';"
mysql_root -e "GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'localhost';"
mysql_root -e "GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'127.0.0.1';"
# The panel IS the DBA: it provisions databases/users (needs global CREATE,
# DROP, CREATE USER and GRANT OPTION to delegate grants), and the MySQL
# Governor needs PROCESS + KILL over other users' threads. Localhost-only +
# random password. NOTE: ALL PRIVILEGES alone is not enough — without WITH
# GRANT OPTION every GRANT the panel issues fails with 1044.
mysql_root -e "GRANT ALL PRIVILEGES ON *.* TO '$DB_USERNAME'@'localhost' WITH GRANT OPTION;"
mysql_root -e "GRANT ALL PRIVILEGES ON *.* TO '$DB_USERNAME'@'127.0.0.1' WITH GRANT OPTION;"

# PowerDNS gmysql database + user + schema.
SCHEMA="/usr/share/doc/pdns/schema.mysql.sql"
[ -f "$SCHEMA" ] || SCHEMA="$(rpm -ql pdns-server 2>/dev/null | grep -E 'schema.mysql.sql$' | head -1)"
[ -n "${SCHEMA:-}" ] && [ -f "$SCHEMA" ] || die "could not locate pdns schema.mysql.sql"
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$PDNS_DB\` CHARACTER SET utf8mb4;"
mysql_root -e "CREATE USER IF NOT EXISTS '$PDNS_USER'@'localhost' IDENTIFIED BY '$PDNS_PASSWORD';
ALTER USER '$PDNS_USER'@'localhost' IDENTIFIED BY '$PDNS_PASSWORD';"
mysql_root -e "GRANT ALL PRIVILEGES ON \`$PDNS_DB\`.* TO '$PDNS_USER'@'localhost';"
if ! mysql_root -N -e "USE \`$PDNS_DB\`; SHOW TABLES" 2>/dev/null | grep -q .; then
    mysql_root "$PDNS_DB" < "$SCHEMA"
    step "pdns schema loaded"
else
    step "pdns schema already present"
fi
mysql_root -e "FLUSH PRIVILEGES;"
step "databases + users ready"

# ---------------------------------------------------------------------------
log "3/7 PowerDNS"
SOA_HOST="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"
cat > /etc/pdns/pdns.conf <<EOF
launch=gmysql
gmysql-host=${DB_HOST}
gmysql-port=${DB_PORT}
gmysql-dbname=${PDNS_DB}
gmysql-user=${PDNS_USER}
gmysql-password=${PDNS_PASSWORD}
gmysql-dnssec=yes
local-address=127.0.0.1,::1
query-cache-ttl=0
negquery-cache-ttl=0
domain-metadata-cache-ttl=1
# New/changed zones must propagate in about a minute, not the 5-minute
# stock zone-cache interval (customers expect a new domain to resolve now,
# and every e2e would otherwise sleep through the cache window).
zone-cache-refresh-interval=60
default-soa-content=${DNS_NAMESERVER:-ns1.$SOA_HOST} hostmaster.$SOA_HOST 0 10800 3600 604800 3600
EOF
chown root:pdns /etc/pdns/pdns.conf
chmod 640 /etc/pdns/pdns.conf
systemctl enable --now pdns >/dev/null 2>&1 || true
systemctl restart pdns >/dev/null 2>&1 || true
step "pdns (gmysql) enabled"

# NOTE: phpMyAdmin + Roundcube install after step 7 (they need MariaDB up;
# Roundcube additionally needs a .env carrying MAIL_MASTER_PASSWORD).

# ---------------------------------------------------------------------------
log "4/7 Mail (postfix + dovecot)"
systemctl enable --now postfix >/dev/null 2>&1 || true
# M-13: local-submission envelope-sender wrapper.
install -m 0755 -o root -g root "$here/deploy/cwhpanel-netns" /usr/local/sbin/cwhpanel-netns
/usr/local/sbin/cwhpanel-netns host-setup >/dev/null 2>&1 || true
install -m 0644 "$here/deploy/cwhpanel-modsec-logrotate" /etc/logrotate.d/cwhpanel-modsec
install -m 0644 "$here/deploy/cwhpanel-tmp.sh" /etc/profile.d/cwhpanel-tmp.sh
install -m 0755 -o root -g root "$here/deploy/cwhpanel-sendmail" /usr/local/sbin/cwhpanel-sendmail
ln -sf /usr/local/sbin/cwhpanel-sendmail /usr/sbin/sendmail
bash "$here/scripts/postfix-submission-sender.sh" >/dev/null 2>&1 || true
systemctl enable --now dovecot >/dev/null 2>&1 || true

# OpenDKIM (DKIM signing) — the panel already generates per-domain keys into
# /etc/cwhpanel/dkim/<domain>.priv and publishes default._domainkey TXT; the
# milter is what finally signs outbound mail. The agent op `dkim.signing.sync`
# (HostnameService::rebuildOpendkimTables) maintains the KeyTable/SigningTable
# whenever a key is provisioned; here we only install/wire the milter.
dnf -q install -y opendkim opendkim-tools >/dev/null 2>&1 || true
mkdir -p /etc/opendkim /etc/cwhpanel/dkim
# Always install OUR config (backup on change). The RPM ships a default that
# points at /etc/opendkim/keys/default.private — which does not exist — so
# opendkim exits 78 on boot and postfix DEFERS every message on the milter.
# "if missing" only worked on boxes that had never had the package installed.
if ! cmp -s "$here/deploy/opendkim.conf" /etc/opendkim.conf 2>/dev/null; then
  [ -f /etc/opendkim.conf ] && cp -a /etc/opendkim.conf "/etc/opendkim.conf.bak-$(date +%Y%m%d-%H%M%S)" || true
  install -m 0644 "$here/deploy/opendkim.conf" /etc/opendkim.conf
fi
systemctl enable opendkim >/dev/null 2>&1 || true
: > /etc/opendkim/hosting-internal-hosts
for h in 127.0.0.1 ::1 localhost "$(hostname -f 2>/dev/null)"; do
  printf '%s\n' "$h" >> /etc/opendkim/hosting-internal-hosts
done
# Also include the panel's public IP so mail relayed via it is signed.
if [ -n "${PANEL_PUBLIC_IP:-}" ]; then
  printf '%s\n' "$PANEL_PUBLIC_IP" >> /etc/opendkim/hosting-internal-hosts
fi
# (re)build tables from any keys already present (fresh builds may seed keys
# before the first agent sync runs).
: > /etc/opendkim/hosting-key.table
: > /etc/opendkim/hosting-signing.table
for k in /etc/cwhpanel/dkim/*.priv; do
  [ -f "$k" ] || continue
  dom="$(basename "$k" .priv)"
  case "$dom" in
    *.*) printf 'default._domainkey.%s %s:default:%s\n' "$dom" "$dom" "$k" >> /etc/opendkim/hosting-key.table
         printf '*@%s default._domainkey.%s\n' "$dom" "$dom" >> /etc/opendkim/hosting-signing.table ;;
  esac
done
chown root:opendkim /etc/opendkim/hosting-key.table /etc/opendkim/hosting-signing.table /etc/opendkim/hosting-internal-hosts 2>/dev/null || true
chmod 0640 /etc/opendkim/hosting-key.table /etc/opendkim/hosting-signing.table 2>/dev/null || true
systemctl enable --now opendkim >/dev/null 2>&1 || true
# Wire the postfix milter: opendkim signs outbound mail.
current_smtpd="$(postconf -h smtpd_milters 2>/dev/null || true)"
if ! printf '%s' "$current_smtpd" | grep -q '8891'; then
  postconf -e "smtpd_milters = ${current_smtpd:+$current_smtpd }inet:localhost:8891"
fi
current_non="$(postconf -h non_smtpd_milters 2>/dev/null || true)"
if ! printf '%s' "$current_non" | grep -q '8891'; then
  postconf -e "non_smtpd_milters = ${current_non:+$current_non }inet:localhost:8891"
fi
systemctl reload postfix >/dev/null 2>&1 || true

# Postfix hardening: disable the SMTP VRFY command (user enumeration vector).
current_vrfy="$(postconf -h disable_vrfy_command 2>/dev/null || true)"
if [ "$current_vrfy" != "yes" ]; then
  postconf -e 'disable_vrfy_command = yes'
  systemctl reload postfix >/dev/null 2>&1 || true
fi

# ClamAV daemon: the stock scan.conf ships with every server type commented
# out, so clamd exits immediately ("Please define server type"). Define the
# local socket (idempotent marker check), ensure the runtime dir survives
# reboot via tmpfiles.d, and let the daemon read scan targets under SELinux.
if ! grep -q "cwhpanel-managed" /etc/clamd.d/scan.conf 2>/dev/null; then
  cat >> /etc/clamd.d/scan.conf <<'EOF'

# cwhpanel-managed: local scan socket (stock file ships everything commented,
# and clamd refuses to start with no server type defined).
LocalSocket /run/clamd.scan/clamd.sock
FixStaleSocket yes
LocalSocketGroup clamscan
LocalSocketMode 660
PidFile /run/clamd.scan/clamd.pid
EOF
fi
printf 'd /run/clamd.scan 0755 clamscan clamscan -\n' > /usr/lib/tmpfiles.d/cwhpanel-clamd.conf
systemd-tmpfiles --create /usr/lib/tmpfiles.d/cwhpanel-clamd.conf >/dev/null 2>&1 || true
setsebool -P antivirus_can_scan_system=on 2>/dev/null || true
# clamd refuses to start with no virus databases ("No supported database files
# found in /var/lib/clamav"). Make sure freshclam has fetched them first —
# otherwise clamd@scan fails and just burns through its restart budget.
systemctl enable --now clamav-freshclam >/dev/null 2>&1 || true
db_ready() {
    { [ -f /var/lib/clamav/main.cvd ] || [ -f /var/lib/clamav/main.cld ]; } \
        && { [ -f /var/lib/clamav/daily.cvd ] || [ -f /var/lib/clamav/daily.cld ]; }
}
if ! db_ready; then
    step "waiting for clamav databases (freshclam, up to ~10 min)..."
    for i in $(seq 1 60); do
        sleep 10
        if db_ready; then break; fi
    done
fi
if db_ready; then
    step "clamav databases present"
else
    warn "no clamav databases yet (freshclam still downloading; start clamd@scan manually later)"
fi
systemctl enable --now clamd@scan >/dev/null 2>&1 || warn "clamd@scan failed to start (see journalctl -u clamd@scan)"

# ---------------------------------------------------------------------------
log "4b/7 Shell hardening (hidepid, compiler perms)"
# Customers must not see each other's processes. hidepid=2 hides foreign
# PIDs entirely (kernel reports it as "invisible"); root and monitoring
# (which never reads foreign /proc/<pid> as non-root) are unaffected.
if ! grep -qE "^\s*proc\s+/proc\s" /etc/fstab 2>/dev/null; then
  printf 'proc /proc proc defaults,hidepid=2 0 0\n' >> /etc/fstab
fi
mount -o remount,hidepid=2 /proc 2>/dev/null || warn "hidepid remount failed"
# Compilers/assemblers are root-only: customers cannot build local exploits
# (uploaded static binaries remain possible — this is friction, not a wall).
# Root builds (e.g. cargo for the agent) keep working. RPM updates may reset
# these modes; scripts/pentest-infra.sh asserts them.
for tool in /usr/bin/gcc /usr/bin/make /usr/bin/as /usr/bin/ld; do
  [ -e "$tool" ] && chmod 700 "$tool" 2>/dev/null || true
done
# Disk quotas: the panel provisions per-account XFS user quotas (soft = package
# limit, hard = +100MB), but the kernel only enforces them when the filesystem
# is mounted with usrquota. Patch the root fs entry (takes effect on reboot —
# XFS silently ignores quota-on-remount, verified live).
if grep -qE '^[^#]*\s+/\s+xfs\s' /etc/fstab 2>/dev/null && ! grep -qE '^[^#]*\s+/\s+xfs\s+[^\s]*usrquota' /etc/fstab 2>/dev/null; then
  sed -i -E '/usrquota/! s|^([^#]*\s+/\s+xfs\s+)([a-z0-9_,=.-]+)|\1\2,usrquota|' /etc/fstab
  findmnt --verify >/dev/null 2>&1 || warn "/etc/fstab failed verification after usrquota patch"
fi
# The fstab patch alone NEVER activates quotas: XFS ignores quota options on
# remount, and systemd's remount of / at boot is exactly that — the option
# must be present at initial mount time via rootflags, or the fs stays
# `noquota` (verified live: correct fstab + reboot = still noquota).
# Persist for current entries (grubby) and future kernels (/etc/default/grub);
# takes effect on the next reboot.
if ! grep -qE '^GRUB_CMDLINE_LINUX=.*rootflags' /etc/default/grub 2>/dev/null; then
  printf '\nGRUB_CMDLINE_LINUX="rootflags=usrquota"\n' >> /etc/default/grub
fi
if command -v grubby >/dev/null 2>&1; then
  if grubby --info=DEFAULT 2>/dev/null | grep -q 'rootflags=.*quota'; then
    step "quota rootflags already present"
  elif grubby --update-kernel=ALL --args="rootflags=usrquota" >/dev/null 2>&1; then
    step "rootflags=usrquota set — quotas activate on next reboot"
  else
    warn "grubby failed to set rootflags; add rootflags=usrquota to the kernel cmdline manually"
  fi
fi
step "shell hardening applied"

log "4c/7 sysctl hardening"
cp "$(dirname "$0")/../deploy/cwhpanel-sysctl.conf" /etc/sysctl.d/99-cwhpanel.conf 2>/dev/null || warn "sysctl drop-in copy failed"
sysctl --system >/dev/null 2>&1 || warn "sysctl --system failed; reboot to apply"
step "sysctl hardening applied"

log "4c2/7 MariaDB performance tuning"
cp "$(dirname "$0")/../deploy/cwhpanel-mariadb-tuning.cnf" /etc/my.cnf.d/99-cwhpanel-tuning.cnf 2>/dev/null || warn "mariadb tuning drop-in copy failed"
step "mariadb tuning file installed (restart mariadb to apply)"

log "4c3/7 nginx performance tuning"
cp "$(dirname "$0")/../deploy/cwhpanel-nginx-perf.conf" /etc/nginx/conf.d/cwhpanel-perf.conf 2>/dev/null || warn "nginx perf drop-in copy failed"
cp "$(dirname "$0")/../deploy/cwhpanel-http-hardening.conf" /etc/nginx/conf.d/cwhpanel-http-hardening.conf 2>/dev/null || warn "nginx http-hardening drop-in copy failed"
# Raise the *soft* fd limit too: systemd's default is 1024:524288, which makes
# nginx's `worker_rlimit_nofile 65535` hit EACCES at every boot (workers then
# run with only 1024 fds). Pin both ends so the directive applies cleanly.
mkdir -p /etc/systemd/system/nginx.service.d
printf '[Service]\nLimitNOFILE=65535:65535\n' > /etc/systemd/system/nginx.service.d/cwhpanel-nofile.conf
systemctl daemon-reload >/dev/null 2>&1 || true
step "nginx perf file installed (reload nginx to apply)"

log "4c5/7 quick-win tuning (opcache / static expires / log retention)"
OPCACHE=/etc/php.d/99-cwhpanel-opcache.ini
cp "$(dirname "$0")/../deploy/cwhpanel-opcache.ini" "$OPCACHE" 2>/dev/null || warn "opcache drop-in copy failed"
for v in 74 80 81 82 83; do
  d=/etc/opt/remi/php$v/php.d
  if [ -d "$d" ]; then
    cp "$(dirname "$0")/../deploy/cwhpanel-opcache.ini" "$d/99-cwhpanel-opcache.ini"
  fi
done
sed 's#__PANEL_DIR__#/opt/cwhpanel#' "$(dirname "$0")/../deploy/cwhpanel-logprune.cron" > /etc/cron.d/cwhpanel-logprune 2>/dev/null || warn "logprune cron install failed"
chmod 644 /etc/cron.d/cwhpanel-logprune
sed 's#__PANEL_DIR__#/opt/cwhpanel#' "$(dirname "$0")/../deploy/cwhpanel-dbquota.cron" > /etc/cron.d/cwhpanel-dbquota 2>/dev/null || warn "db-quota cron install failed"
chmod 644 /etc/cron.d/cwhpanel-dbquota
sed 's#__PANEL_DIR__#/opt/cwhpanel#' "$(dirname "$0")/../deploy/cwhpanel-panelcheck.cron" > /etc/cron.d/cwhpanel-panelcheck 2>/dev/null || warn "panel-update-check cron install failed"
chmod 644 /etc/cron.d/cwhpanel-panelcheck
sed 's#__PANEL_DIR__#/opt/cwhpanel#' "$(dirname "$0")/../deploy/cwhpanel-panelmanager.cron" > /etc/cron.d/cwhpanel-panelmanager 2>/dev/null || warn "panelmanager cron install failed"
chmod 644 /etc/cron.d/cwhpanel-panelmanager
patch_panel_static() {
python3 - <<'PY' 2>/dev/null || return 1
import re
p = '/etc/nginx/conf.d/panel.conf'
s = open(p).read()
if 'cwhpanel static' not in s:
    block = '''    # Public static assets (versioned via ?v=mtime -- safe to cache long).
    location ~* \\.(?:css|js|woff2?|eot|ttf|otf|svg|png|jpe?g|gif|webp|ico)$ {
        expires 7d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

'''
    s = s.replace('    # Front controller\n', block + '    # Front controller\n', 1)
    open(p, 'w').write(s)
PY
}
# panel.conf is rendered later in this script; patch it there.
step "quick-win tuning applied (restart php-fpm + reload nginx)"
cp "$(dirname "$0")/../deploy/cwhpanel-pdns-tuning.conf" /etc/pdns/cwhpanel-tuning.conf 2>/dev/null || warn "pdns tuning copy failed"
# PowerDNS has no include directive — merge KEY=VALUE lines, replacing stale
# values (e.g. query-cache-ttl=0 defaults).
python3 - <<'PY' 2>/dev/null || warn "pdns tuning merge failed"
conf = '/etc/pdns/pdns.conf'
snip = '/etc/pdns/cwhpanel-tuning.conf'
want = {}
for ln in open(snip):
    ln = ln.strip()
    if not ln or ln.startswith('#'):
        continue
    k, _, v = ln.partition('=')
    want[k] = v
out = [ln for ln in open(conf).read().splitlines() if ln.split('=', 1)[0].strip() not in want]
for k, v in want.items():
    out.append(f"{k}={v}")
open(conf, 'w').write('\n'.join(out) + '\n')
PY
step "pdns tuning merged into pdns.conf (restart pdns to apply)"

# The agent writes the canonical postfix/dovecot virtual-user config via
# mail.setup / mail.smtp.setup. They read the panel schema, so they run after
# step 7 (migrations) — see the post-seed block. PROVISIONING needs the agent
# socket, which must already be up (step 6).
MAIL_SETUP_MARK="/etc/dovecot/conf.d/99-cwhpanel.conf"
step "mail services enabled (agent applies virtual-user config in step 6)"

# ---------------------------------------------------------------------------
log "5/7 SELinux MCS policy"
if command -v semodule >/dev/null 2>&1; then
    ( cd "$here/agent/selinux" && bash setup.sh ) || warn "SELinux policy setup failed (continuing)"
    # Per-account FPM reads account homes via explicit allow rules; the agent
    # also toggles these booleans during provisioning, but set them now too.
    setsebool -P httpd_enable_homedirs=on 2>/dev/null || true
    setsebool -P httpd_read_user_content=on 2>/dev/null || true
    # Allow the confined panel/PHP-FPM to reach MariaDB over localhost.
    setsebool -P httpd_can_network_connect_db=on 2>/dev/null || true
    # Allow PHP-FPM workers to make outbound HTTPS/API calls (WordPress
    # updates, api.wordpress.org, feeds — else cURL error 7).
    setsebool -P httpd_can_network_connect=on 2>/dev/null || true
else
    warn "SELinux tooling missing; running without policy (not recommended)"
fi

# ---------------------------------------------------------------------------
# The live tree's .env is what the running services read; the source .env is
# only a build-time copy. Keep them in step after every secret mutation.
# Agent trust-model ticket keys: the panel mints, the agent verifies. They must
# exist BEFORE install.sh installs the agent (its override carries the copies)
# and must live in both env files — a later env sync used to wipe keys generated
# only in the live tree, leaving every provisioning call "ticket rejected: bad
# signature".
for VAR in CWH_PANEL_ADMIN_KEY CWH_PANEL_CUSTOMER_KEY; do
    if ! grep -qE "^${VAR}=.+" "$here/.env"; then
        KEY_NEW="$(head -c 32 /dev/urandom | base64 | tr -d '=+/' | head -c 44)"
        echo "${VAR}=${KEY_NEW}" >> "$here/.env"
        step "generated ${VAR}"
    fi
done

sync_env_live() {
    if [ -d "$LIVE_DIR" ] && [ "$here" != "$LIVE_DIR" ]; then
        cp -a "$here/.env" "$LIVE_DIR/.env" 2>/dev/null || return 0
        chown root:cwhpanel-panel "$LIVE_DIR/.env" 2>/dev/null || true
        chmod 640 "$LIVE_DIR/.env" 2>/dev/null || true
    fi
}

log "6/7 Panel services (agent + worker + mail-policy)"
# The panel FPM pool runs as its own dedicated user (cwhpanel-panel) — never
# nginx — so web workers cannot read panel secrets. Create it BEFORE install.sh:
# the agent derives its peer allowlist from the pool user, the units embed the
# panel directory, and on a fresh box the missing group aborts the run.
# 980/989 are preferred (docs/tests assume them) but dovecot's `dovenull`
# already owns 989 on a fresh box — fall back to auto-assigned ids when taken.
ensure_panel_user() {
    if ! getent group cwhpanel-panel >/dev/null 2>&1; then
        groupadd -r -g 989 cwhpanel-panel 2>/dev/null || groupadd -r cwhpanel-panel
    fi
    if ! getent passwd cwhpanel-panel >/dev/null 2>&1; then
        useradd -r -u 980 -g cwhpanel-panel -s /sbin/nologin -d "$1" cwhpanel-panel 2>/dev/null || \
        useradd -r -g cwhpanel-panel -s /sbin/nologin -d "$1" cwhpanel-panel
    fi
}
LIVE_DIR="${LIVE_DIR:-/opt/cwhpanel}"
ensure_panel_user "$LIVE_DIR"

# The service units and cron drop-ins embed the panel directory, so they must
# be installed from the LIVE tree, not from wherever the repo was cloned (a
# clone has no /opt/cwhpanel yet — the worker would have run the checkout while
# nginx served the live tree). Sync it now; step 7a re-syncs after the host is
# configured.
mkdir -p "$LIVE_DIR"
command -v rsync >/dev/null 2>&1 || dnf -y install rsync >/dev/null 2>&1 || true
if [ "$here" != "$LIVE_DIR" ]; then
    rsync -a --exclude 'agent/target' --exclude 'storage/*' --exclude '.env' --exclude '.git' \
        "$here/" "$LIVE_DIR/" || die "rsync to $LIVE_DIR failed"
fi
[ "$here" = "$LIVE_DIR" ] || ([ -f "$here/.env" ] && cp -a "$here/.env" "$LIVE_DIR/.env")

# The dedicated pool file lets install.sh resolve the panel uid/gid (it prefers
# /etc/php-fpm-panel.d); without it detection falls back to the stock apache
# pool and the agent allowlists the wrong peer.
if [ ! -f /etc/php-fpm-panel.d/panel.conf ]; then
    mkdir -p /etc/php-fpm-panel.d
    cp "$here/deploy/php-fpm-panel.conf.example" /etc/php-fpm-panel.d/panel.conf
    step "panel php-fpm pool installed (dedicated instance, pre-services)"
fi

( cd "$LIVE_DIR" && bash ./install.sh --prod )
# The agent reads its trust-model keys from the unit environment at START; a
# re-generated key in .env must be adopted now (a stale running agent rejects
# every minted ticket with "bad signature").
systemctl restart cwhpanel-agent.service >/dev/null 2>&1 || true
systemctl restart cwhpanel-worker.service >/dev/null 2>&1 || true

# System hardening: hide the exact PHP version; keep backup artifacts root-only.
for ini in /etc/php.ini /etc/opt/remi/php*/php.ini; do
    [ -f "$ini" ] || continue
    sed -i -E 's/^;?expose_php[[:space:]]*=.*/expose_php = Off/' "$ini"
done
systemctl try-restart php-fpm php74-php-fpm php80-php-fpm php81-php-fpm php82-php-fpm php83-php-fpm >/dev/null 2>&1 || true
# Backup artifacts: root-owned, readable by the panel's FPM group so the
# panel can list sizes and stream downloads (app-level ownership checks gate
# which row/artifact a customer may fetch). Not world-accessible.
install -d -m 0750 -o root -g cwhpanel-panel /var/backups
step "system hardening applied (expose_php=Off, /var/backups 0750 root:cwhpanel-panel)"

# PHP session dirs must be world-writable+sticky (1733, Debian layout):
# FPM pools run as account users (and nginx), but RPM ships the dirs
# root:apache 0770, so native session_start() fails everywhere except
# httpd workers in the apache group. Session files stay 0600 per owner.
for sdir in /var/lib/php/session /var/opt/remi/php*/lib/php/session; do
    [ -d "$sdir" ] && chmod 1733 "$sdir"
done
step "PHP session dirs opened to 1733 (account-pool native sessions)"

# rpcbind serves NFS/portmap — nothing in the panel uses it, and it
# listens publicly on 111/tcp by default. Disable so fresh boxes converge
# to the hardened state (matches the infra pentest expectation).
systemctl disable --now rpcbind.socket rpcbind.service >/dev/null 2>&1 || true
step "rpcbind disabled (unused by the panel, was publicly listening on 111)"

# Brute-force protection: fail2ban jails for SSH (stricter, in jail.local)
# plus Dovecot IMAP/POP3, Postfix SASL and vsftpd FTP (drop-in). Dovecot
# also covers Roundcube + panel webmail, which both auth against it over
# IMAP (Roundcube emits no login log of its own, so no roundcube-auth jail).
if ! rpm -q fail2ban-server >/dev/null 2>&1; then
    dnf install -y fail2ban fail2ban-systemd fail2ban-firewalld >/dev/null 2>&1 || warn "fail2ban install failed"
fi
if [ -f "$here/deploy/cwhpanel-fail2ban.conf" ]; then
    cp "$here/deploy/cwhpanel-fail2ban.conf" /etc/fail2ban/jail.d/cwhpanel.conf
    if ! grep -q "^\[sshd\]" /etc/fail2ban/jail.local 2>/dev/null; then
        printf '\n[sshd]\nenabled = true\nport = ssh\nfilter = sshd\nlogpath = %%(sshd_log)s\nbackend = %%(sshd_backend)s\nmaxretry = 3\nbantime = 7200\n' >> /etc/fail2ban/jail.local
    fi
    systemctl enable --now fail2ban >/dev/null 2>&1 || warn "fail2ban failed to start"
    fail2ban-client reload >/dev/null 2>&1 || warn "fail2ban reload failed"
    step "fail2ban jails enabled (sshd, dovecot, postfix-sasl, vsftpd)"
    # Web-scanner / botnet jail: bans hosts probing dotfile/shell/cgi/backup
    # signatures in the nginx access logs (IP-level bounce, complements the
    # WAF's per-request 403s). Bans land as firewalld rich rules on 80/443 so
    # they are visible + unblockable on the admin Firewall page.
    if [ -f "$here/deploy/fail2ban-filter-nginx-scanner.conf" ] && [ -f "$here/deploy/cwhpanel-fail2ban-scanner.conf" ]; then
        cp "$here/deploy/fail2ban-filter-nginx-scanner.conf" /etc/fail2ban/filter.d/cwhpanel-nginx-scanner.conf
        cp "$here/deploy/cwhpanel-fail2ban-scanner.conf" /etc/fail2ban/jail.d/cwhpanel-scanner.conf
        fail2ban-client reload >/dev/null 2>&1 || warn "fail2ban scanner jail reload failed"
        step "fail2ban web-scanner jail enabled (dotfile/shell/cgi/backup probes)"
    fi
fi

# Provisioning must be live on a real install. .env.example ships
# PROVISIONING_ENABLED=false (safe for dev); turn-up is production.
if grep -qE '^PROVISIONING_ENABLED=' "$here/.env"; then
    sed -i "s#^PROVISIONING_ENABLED=.*#PROVISIONING_ENABLED=true#" "$here/.env"
else
    echo "PROVISIONING_ENABLED=true" >> "$here/.env"
fi
step "provisioning enabled (PROVISIONING_ENABLED=true)"

# Let's Encrypt must be PRODUCTION on a real install: the example env defaults
# to staging (safe for dev), which yields an untrusted certificate and browsers
# reject the panel. Operators can still force staging by exporting it.
ACME_DIR="${ACME_DIRECTORY:-https://acme-v02.api.letsencrypt.org/directory}"
for envf in "$here/.env" "$LIVE_DIR/.env"; do
    [ -f "$envf" ] || continue
    if grep -qE '^ACME_DIRECTORY=' "$envf"; then
        sed -i "s#^ACME_DIRECTORY=.*#ACME_DIRECTORY=${ACME_DIR}#" "$envf"
    else
        echo "ACME_DIRECTORY=${ACME_DIR}" >> "$envf"
    fi
done
step "ACME directory pinned to ${ACME_DIR}"

# Production defaults: a released panel must never run in debug mode (error
# pages would leak paths/SQL/config). Explicitly pin them on every turn-up.
for kv in APP_ENV=production APP_DEBUG=false; do
    key="${kv%%=*}"; val="${kv#*=}"
    if grep -qE "^${key}=" "$here/.env"; then
        sed -i "s#^${key}=.*#${key}=${val}#" "$here/.env"
    else
        echo "${key}=${val}" >> "$here/.env"
    fi
done
step "APP_ENV=production, APP_DEBUG=false"

# ---------------------------------------------------------------------------
log "7/7 Database schema + seed"
# Write DB credentials back into .env if not present, so scripts/install.php
# and the worker/policy daemons use the exact credentials created above.
# Also generate any missing secrets that the app needs at runtime.
touch "$here/.env"
for kv in "DB_HOST=$DB_HOST" "DB_PORT=$DB_PORT" "DB_DATABASE=$DB_DATABASE" \
          "DB_USERNAME=$DB_USERNAME" "DB_PASSWORD=$DB_PASSWORD"; do
    key="${kv%%=*}"; val="${kv#*=}"
    if grep -qE "^${key}=" "$here/.env"; then
        sed -i "s#^${key}=.*#${key}=${val}#" "$here/.env"
    else
        echo "${key}=${val}" >> "$here/.env"
    fi
done
# Webmail single sign-on secret (Dovecot master-user auth). Generate if absent
# so the panel can open customer mailboxes without their passwords.
if ! grep -qE '^MAIL_MASTER_PASSWORD=..+' "$here/.env"; then
    MAIL_MP="$(head -c 32 /dev/urandom | base64 | tr -d '=+/' | head -c 40)"
    if grep -qE '^MAIL_MASTER_PASSWORD=' "$here/.env"; then
        sed -i "s#^MAIL_MASTER_PASSWORD=.*#MAIL_MASTER_PASSWORD=$MAIL_MP#" "$here/.env"
    else
        echo "MAIL_MASTER_PASSWORD=$MAIL_MP" >> "$here/.env"
    fi
    step "generated MAIL_MASTER_PASSWORD"
fi
# Dovecot virtual-user config + Postfix SASL/LMTP sockets (99-cwhpanel.conf).
# Without this, submission advertises no AUTH and LMTP delivery has no socket;
# the Email module re-applies it per domain, but a fresh box needs it now.
# NOTE: mail.setup runs AFTER migrations (step 7) — it reads panel tables and
# used to fail here with a scary (and misleading) warning on a fresh install.
# Only the empty map/passwd files can be seeded this early.
if [ -S /run/cwhpanel/agent.sock ]; then
    # The maps and passwd-files those configs point at must exist even before
    # the first domain/mailbox exists, otherwise every SMTP/IMAP connection
    # logs "open database ... No such file" / "passwd-file ... failed".
    for map in virtual.cwhpanel vmaildomains sender_logins; do
        [ -f "/etc/postfix/$map" ] || : > "/etc/postfix/$map"
        postmap "/etc/postfix/$map" >/dev/null 2>&1 || warn "postmap $map failed"
    done
    for f in /etc/dovecot/cwhpanel-passwd /etc/dovecot/cwhpanel-users; do
        [ -f "$f" ] || : > "$f"
        chown root:dovecot "$f" 2>/dev/null || true
        chmod 640 "$f"
    done
    systemctl reload dovecot postfix >/dev/null 2>&1 || true
fi
# Session/app encryption key (APP_KEY). Required by Session::sessionKey() —
# an empty key would make session-payload encryption use a public constant.
if ! grep -qE '^APP_KEY=.{16,}' "$here/.env"; then
    APP_KEY_NEW="$(head -c 32 /dev/urandom | base64 | tr -d '=+/' | head -c 44)"
    if grep -qE '^APP_KEY=' "$here/.env"; then
        sed -i "s#^APP_KEY=.*#APP_KEY=$APP_KEY_NEW#" "$here/.env"
    else
        echo "APP_KEY=$APP_KEY_NEW" >> "$here/.env"
    fi
    step "generated APP_KEY"
fi
sync_env_live
# Public IP of this host (used for provisioned DNS A records). Detect when
# absent/empty; never clobber an operator-set value.
if ! grep -qE '^PANEL_PUBLIC_IP=[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' "$here/.env"; then
    DETECTED_IP=""
    for url in "https://api.ipify.org" "https://ifconfig.me/ip"; do
        DETECTED_IP="$(curl -fsS --max-time 8 "$url" 2>/dev/null | tr -d ' \t\r\n' || true)"
        if [[ "$DETECTED_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            break
        fi
        DETECTED_IP=""
    done
    if [[ -z "$DETECTED_IP" ]]; then
        DETECTED_IP="$(ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K[0-9.]+' || true)"
    fi
    if [[ "$DETECTED_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        # Both envs: the live tree's .env is what provisioning reads, the source
        # copy is what the final mirror preserves.
        for envf in "$here/.env" "$LIVE_DIR/.env"; do
            [ -f "$envf" ] || continue
            if grep -qE '^PANEL_PUBLIC_IP=' "$envf"; then
                sed -i "s#^PANEL_PUBLIC_IP=.*#PANEL_PUBLIC_IP=$DETECTED_IP#" "$envf"
            else
                echo "PANEL_PUBLIC_IP=$DETECTED_IP" >> "$envf"
            fi
        done
        step "detected public IP: $DETECTED_IP"
    else
        step "WARNING: could not detect public IP; set PANEL_PUBLIC_IP in .env manually"
    fi
fi
# Panel base URL (absolute links/redirects/assets). Fill when absent, empty,
# or still the dev default — never clobber an operator-set value. Reuses the
# detected public IP; switch to https://your-domain once TLS is in front.
if ! grep -qE '^APP_URL=https?://[^/]+' "$here/.env" || grep -qE '^APP_URL=http://127\.0\.0\.1' "$here/.env"; then
    if [[ "${DETECTED_IP:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        if grep -qE '^APP_URL=' "$here/.env"; then
            sed -i "s#^APP_URL=.*#APP_URL=http://$DETECTED_IP/#" "$here/.env"
        else
            echo "APP_URL=http://$DETECTED_IP/" >> "$here/.env"
        fi
        step "set APP_URL to http://$DETECTED_IP/"
    else
        step "WARNING: APP_URL not set; links will fall back to 127.0.0.1:8080"
    fi
fi
chmod 600 "$here/.env"

# install.php/seed.php persist generated credentials into BASE_PATH/.env, so
# they MUST run from the LIVE tree. Running them from the source checkout wrote
# ADMIN_PASSWORD to the wrong env and the final env mirror then replaced it with
# the empty live value — a fresh install shipped with no usable admin password.
( cd "$LIVE_DIR" && php scripts/install.php )
step "schema migrated + seeded"

# Mail virtual-user config needs BOTH the migrated DB and the running agent;
# the earlier step only marks the intent. Run it here (idempotent) so a fresh
# box can actually send/receive mail without opening the UI.
if [ -S /run/cwhpanel/agent.sock ]; then
    # mail.setup carries the Dovecot master password (webmail SSO) — it is only
    # written when passed, so it MUST be part of this call.
    MAIL_MP_V="$(env_get MAIL_MASTER_PASSWORD)"
    for op in mail.setup mail.smtp.setup; do
        applied=0
        # The agent accepts connections a moment before it is fully usable;
        # retry briefly instead of warning on a fresh install.
        for attempt in 1 2 3; do
            if [ "$op" = "mail.setup" ]; then
                ( cd "$here" && MAIL_MP_V="$MAIL_MP_V" php -r 'require "scripts/_bootstrap.php"; $a=new App\Foundation\Application(BASE_PATH); $a->bootstrap(); app("provisioning")->execute("mail.setup", ["master_password" => (string) getenv("MAIL_MP_V")]);' ) >/dev/null 2>&1 && { applied=1; break; }
            else
                ( cd "$here" && php -r "require 'scripts/_bootstrap.php'; \$a=new App\Foundation\Application(BASE_PATH); \$a->bootstrap(); app('provisioning')->execute('$op', []);" ) >/dev/null 2>&1 && { applied=1; break; }
            fi
            sleep 3
        done
        [ "$applied" = "1" ] && step "$op applied via agent" || warn "$op failed (re-run from the panel's Email settings)"
    done
fi

# Side-by-side app runtimes (best effort): the panel offers Node 20/22 per app
# and a fresh box only ships the primary 26 runtime. Without them every Node
# app that selects 20/22 fails "runtime is not installed" until an admin visits
# Admin → Runtimes.
if [ -S /run/cwhpanel/agent.sock ] && [ -f "$LIVE_DIR/scripts/install-app-runtimes.php" ]; then
    ( cd "$LIVE_DIR" && php scripts/install-app-runtimes.php ) 2>&1 | while IFS= read -r l; do step "$l"; done \
        || warn "some app runtimes could not be installed (Admin → Runtimes retries)"
fi


# L-14: isolate each account's applications in their own network namespace by
# default (per-account veth + NAT + inter-account block). Toggle in Network.
( cd "$here" && php -r 'require "scripts/_bootstrap.php"; $a=new App\Foundation\Application(BASE_PATH); $a->bootstrap(); app(\Modules\Network\Services\AppNetnsService::class)->setEnabled(true);' ) >/dev/null 2>&1     && step "app network isolation enabled"     || warn "app network isolation setting not applied"

# Post-seed convergence (upgrades + fresh hardening):
# - scope the panel MariaDB account (revoke FILE/SUPER);
# - rotate the published seed default passwords (new values land in .env).
php "$here/scripts/install-db-admin-procedures.php" >/dev/null 2>&1 && step "panel DB least-privilege procedures installed" || warn "panel DB scoping skipped"
php "$here/scripts/rotate-default-credentials.php" >/dev/null 2>&1 && step "seed default credentials rotated (values in .env)" || warn "credential rotation skipped"
# Enforce admin 2FA ONLY when every active admin already enrolled TOTP
# (safe on fresh installs after seed/enrollment; never locks anyone out).
php "$here/scripts/enforce-admin-2fa.php" >/dev/null 2>&1 && step "admin 2FA requirement enforced" || warn "admin 2FA requirement left off (an admin has no TOTP yet)"

# ---------------------------------------------------------------------------
log "7a/7 Panel web front (live tree, php-fpm pool, nginx vhost)"
# Fully automatic: syncs the live tree to /opt/cwhpanel, renders the php-fpm
# pool + nginx vhost from the deploy examples, opens http/https, and points
# APP_URL at the panel host. Step 7b then upgrades it to https when the
# hostname allows (it hard-requires /etc/nginx/conf.d/panel.conf).
LIVE_DIR="/opt/cwhpanel"

# -- Pick the panel host: explicit flag > interactive prompt (server hostname
# as default) > server hostname when it is a real FQDN > public IP.
PANEL_HOST=""
PANEL_HOST_EXPLICIT=0
HN="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo '')"
if [ -n "${PANEL_DOMAIN:-}" ]; then
    PANEL_HOST="$PANEL_DOMAIN"
    PANEL_HOST_EXPLICIT=1
elif [ "$NONINTERACTIVE" = "0" ] && [ -t 0 ]; then
    printf 'Panel domain (FQDN, e.g. panel.example.com) [%s]: ' "$HN"
    read -r ans || true
    PANEL_HOST="${ans:-$HN}"
    PANEL_HOST_EXPLICIT=1
else
    if [[ "$HN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*\.)+[A-Za-z]{2,}$ ]] \
        && [[ "$HN" != *.local ]] && [[ "$HN" != *.localhost ]] \
        && [[ "$HN" != *.internal ]] && [[ "$HN" != *.invalid ]]; then
        PANEL_HOST="$HN"
    elif [[ "${DETECTED_IP:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        PANEL_HOST="$DETECTED_IP"
    else
        PANEL_HOST="_"
    fi
fi
[ -n "$PANEL_HOST" ] || PANEL_HOST="_"
step "panel host: $PANEL_HOST"

# DNS nameservers belong to the SERVER and a fresh install must never advertise
# somebody else's example.com. When the panel host is a real FQDN, default the
# pair to ns1/ns2.<host> (the operator still has to create the glue records);
# an IP-only install leaves them EMPTY, which means "this panel does not host
# DNS" until an administrator sets them in Admin → DNS.
if [[ "$PANEL_HOST" == *.* && "$PANEL_HOST" =~ [a-zA-Z] ]]; then
    set_env_kv() {
        local file="$1" key="$2" val="$3"
        if grep -qE "^${key}=" "$file"; then
            sed -i "s#^${key}=.*#${key}=${val}#" "$file"
        else
            printf '%s=%s\n' "$key" "$val" >> "$file"
        fi
    }
    for envf in "$here/.env" "$LIVE_DIR/.env"; do
        [ -f "$envf" ] || continue
        cur1="$(grep -E '^DNS_NAMESERVER=' "$envf" | head -1 | cut -d= -f2- || true)"
        cur2="$(grep -E '^DNS_NAMESERVER2=' "$envf" | head -1 | cut -d= -f2- || true)"
        if [ -z "$cur1" ] || [ "$cur1" = "ns1.example.com" ]; then
            set_env_kv "$envf" DNS_NAMESERVER "ns1.${PANEL_HOST}"
        fi
        if [ -z "$cur2" ]; then
            set_env_kv "$envf" DNS_NAMESERVER2 "ns2.${PANEL_HOST}"
        fi
    done
    step "DNS nameservers defaulted to ns1/ns2.${PANEL_HOST}"
fi

# -- Sync the live tree (code only: never clobber a live .env or storage).
if [ "$here" != "$LIVE_DIR" ]; then
    rsync -a --exclude 'agent/target' --exclude 'storage/*' --exclude '.env' \
        --exclude '.git' "$here/" "$LIVE_DIR/" || die "rsync to $LIVE_DIR failed"
fi
chown -R root:nginx "$LIVE_DIR"
mkdir -p "$LIVE_DIR/storage/logs"
chown -R nginx:nginx "$LIVE_DIR/storage"
if [ -f "$here/storage/acme-account.key" ] && [ ! -f "$LIVE_DIR/storage/acme-account.key" ]; then
    cp -a "$here/storage/acme-account.key" "$LIVE_DIR/storage/acme-account.key"
fi
[ -f "$LIVE_DIR/storage/acme-account.key" ] && chown root:cwhpanel-panel "$LIVE_DIR/storage/acme-account.key" \
    && chmod 640 "$LIVE_DIR/storage/acme-account.key" || true
if [ ! -f "$LIVE_DIR/.env" ] && [ "$here" != "$LIVE_DIR" ]; then
    cp -a "$here/.env" "$LIVE_DIR/.env"
    step "seeded $LIVE_DIR/.env from repo .env"
fi
# The pool runs as its own dedicated user (cwhpanel-panel) — never nginx —
# so web workers cannot read panel secrets. Create it and scope secrets to it.
if ! getent group cwhpanel-panel >/dev/null 2>&1; then
    groupadd -r -g 989 cwhpanel-panel
fi
if ! getent passwd cwhpanel-panel >/dev/null 2>&1; then
    useradd -r -u 980 -g cwhpanel-panel -s /sbin/nologin -d "$LIVE_DIR" cwhpanel-panel
fi
chown -R cwhpanel-panel:cwhpanel-panel "$LIVE_DIR/storage" 2>/dev/null || true
chown root:cwhpanel-panel "$LIVE_DIR/.env"
chmod 640 "$LIVE_DIR/.env"
restorecon -R "$LIVE_DIR" >/dev/null 2>&1 || true
step "live tree synced to $LIVE_DIR"

# -- Dedicated php-fpm instance for the panel (render once; never overwrite a
# custom pool). The panel must NOT share the system daemon with customer pools:
# writing a customer pool schedules a reload, which resets in-flight panel
# requests (observed as 502 on account creation / API provisioning).
if [ ! -f /etc/php-fpm-panel.d/panel.conf ]; then
    mkdir -p /etc/php-fpm-panel.d
    cp "$here/deploy/php-fpm-panel.conf.example" /etc/php-fpm-panel.d/panel.conf
    step "panel php-fpm pool installed (dedicated instance)"
fi
if [ ! -f /etc/php-fpm-panel.conf ]; then
    cat > /etc/php-fpm-panel.conf <<'FPMCONF'
; cwhpanel dedicated PHP-FPM instance (panel pool only). Kept separate from
; /etc/php-fpm.conf so customer pool reloads cannot reset panel requests.
[global]
pid = /run/php-fpm/panel-master.pid
error_log = /var/log/php-fpm/panel-error.log
daemonize = no
include=/etc/php-fpm-panel.d/*.conf
FPMCONF
fi
if [ ! -f /etc/systemd/system/php-fpm-panel.service ]; then
    cat > /etc/systemd/system/php-fpm-panel.service <<'FPMUNIT'
[Unit]
Description=cwhpanel PHP FastCGI Process Manager (panel only)
After=network.target

[Service]
Type=notify
ExecStartPre=/usr/bin/mkdir -p /run/php-fpm
ExecStart=/usr/sbin/php-fpm --nodaemonize --fpm-config /etc/php-fpm-panel.conf
ExecReload=/bin/kill -USR2 $MAINPID
PrivateTmp=true
RuntimeDirectoryPreserve=yes

[Install]
WantedBy=multi-user.target
FPMUNIT
    systemctl daemon-reload
fi
# Migrate an old shared-daemon pool: release the socket before the new daemon.
if [ -f /etc/php-fpm.d/panel.conf ]; then
    rm -f /etc/php-fpm.d/panel.conf
    systemctl reload-or-restart php-fpm >/dev/null 2>&1 || true
fi
systemctl enable php-fpm-panel.service >/dev/null 2>&1 || true
systemctl restart php-fpm-panel.service >/dev/null 2>&1 || warn "php-fpm-panel failed to start"
step "panel php-fpm daemon ready"
mkdir -p /var/log/php-fpm
touch /var/log/php-fpm/panel.log
chown cwhpanel-panel:cwhpanel-panel /var/log/php-fpm/panel.log 2>/dev/null || true

# -- Roundcube is installed earlier (step 7d) but the panel pool user only
# exists now: give its config + data dirs the panel group so PHP-FPM can read
# them (root:nginx left it unreadable and Roundcube booted with defaults), and
# label the tree so httpd_t may serve it.
if [ -f /usr/share/roundcubemail/config/config.inc.php ]; then
    chown root:cwhpanel-panel /usr/share/roundcubemail/config/config.inc.php 2>/dev/null || true
    chmod 640 /usr/share/roundcubemail/config/config.inc.php 2>/dev/null || true
fi
if [ -f /usr/share/phpMyAdmin/config.inc.php ]; then
    chown root:cwhpanel-panel /usr/share/phpMyAdmin/config.inc.php 2>/dev/null || true
    chmod 640 /usr/share/phpMyAdmin/config.inc.php 2>/dev/null || true
fi
if [ -d /var/lib/roundcube ]; then
    chown -R cwhpanel-panel:cwhpanel-panel /var/lib/roundcube 2>/dev/null || true
fi
if command -v semanage >/dev/null 2>&1 && [ -d /usr/share/roundcubemail ]; then
    semanage fcontext -a -t httpd_sys_content_t '/usr/share/roundcubemail(/.*)?' 2>/dev/null || true
    restorecon -R /usr/share/roundcubemail >/dev/null 2>&1 || true
    semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/roundcube(/.*)?' 2>/dev/null || true
    restorecon -R /var/lib/roundcube >/dev/null 2>&1 || true
    semanage fcontext -a -t httpd_sys_content_t '/usr/share/phpMyAdmin(/.*)?' 2>/dev/null || true
    restorecon -R /usr/share/phpMyAdmin >/dev/null 2>&1 || true
fi
# Panel storage must be writable by httpd_t (sessions are DB-backed, but logs
# and temporary artifacts live here). /opt is usr_t by default: without the
# label every panel warning/exception log write is AVC-denied and silently lost.
if command -v semanage >/dev/null 2>&1 && [ -d "$LIVE_DIR/storage" ]; then
    semanage fcontext -a -t httpd_sys_rw_content_t "${LIVE_DIR}/storage(/.*)?" 2>/dev/null || true
    restorecon -R "$LIVE_DIR/storage" >/dev/null 2>&1 || true
fi
# Backup artifacts remain root-owned but group-readable for the panel pool
# (listing + downloads); refresh existing files.
chgrp -R cwhpanel-panel /var/backups 2>/dev/null || true
chmod 0750 /var/backups 2>/dev/null || true
find /var/backups -type f -exec chmod 0640 {} \; 2>/dev/null || true
# Per-account master sockets live in /run/php-fpm too: a shared-daemon
# restart wipes RuntimeDirectory contents, stranding enabled masters
# socketless (permanent site 502s). Preserve the dir across restarts; the
# watch.php-sockets watchdog check heals any residual case.
mkdir -p /etc/systemd/system/php-fpm.service.d
printf '[Service]\nRuntimeDirectoryPreserve=yes\n' > /etc/systemd/system/php-fpm.service.d/cwhpanel-sockets.conf
systemctl daemon-reload
systemctl enable --now php-fpm >/dev/null 2>&1 || warn "php-fpm failed to start"

# -- The agent allowlist was built in step 6 from whatever pools existed then
# (usually apache). Point it at the panel pool user now, else the panel gets
# 500s on every agent-backed page.
POOL_USER="$(awk -F'=' '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' /etc/php-fpm-panel.d/panel.conf 2>/dev/null || true)"
POOL_GROUP="$(awk -F'=' '/^[[:space:]]*group[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' /etc/php-fpm-panel.d/panel.conf 2>/dev/null || true)"
POOL_USER="${POOL_USER:-nginx}"; POOL_GROUP="${POOL_GROUP:-nginx}"
PUID="$(id -u "$POOL_USER" 2>/dev/null || echo '')"
PGID="$(id -g "$POOL_GROUP" 2>/dev/null || echo '')"
OVERRIDE="/etc/systemd/system/cwhpanel-agent.service.d/override.conf"
if [ -n "$PUID" ] && [ -n "$PGID" ] && ! grep -q "CWH_AGENT_ALLOWED_UIDS=0,${PUID}$" "$OVERRIDE" 2>/dev/null; then
    # Preserve the agent trust-model lines (ticket keys, mode, grace-window
    # old keys) across the rewrite — dropping them would silently revert the
    # trust model to the code default.
    OLD_TRUST_LINES="$(grep -E '^Environment=CWH_AGENT_(ADMIN|CUSTOMER)_KEY(_OLD)?=' "$OVERRIDE" 2>/dev/null || true)"
    {
        echo "[Service]"
        echo "Environment=CWH_AGENT_SOCKET=/run/cwhpanel/agent.sock"
        echo "Environment=CWH_AGENT_ALLOWED_UIDS=0,${PUID}"
        echo "Environment=CWH_AGENT_ALLOWED_GIDS=0,${PGID}"
        echo "Environment=CWH_AGENT_SOCKET_GROUP=${POOL_GROUP}"
        if [ -n "$OLD_TRUST_LINES" ]; then
            printf '%s\n' "$OLD_TRUST_LINES"
        fi
    } > "$OVERRIDE"
    chmod 750 /etc/systemd/system/cwhpanel-agent.service.d 2>/dev/null || true
    chmod 640 "$OVERRIDE" 2>/dev/null || true
    systemctl daemon-reload
    systemctl restart cwhpanel-agent.service >/dev/null 2>&1 || true
    step "agent allowlist updated to ${POOL_USER} (uid $PUID)"
fi

# -- nginx vhost for the panel (render once; a hand-edited vhost is kept).
if [ ! -f /etc/nginx/conf.d/panel.conf ] || grep -q '/path/to/cwhpanel' /etc/nginx/conf.d/panel.conf; then
    [ -f /etc/nginx/conf.d/panel.conf ] && cp -a /etc/nginx/conf.d/panel.conf "/etc/nginx/conf.d/panel.conf.bak-$(date +%Y%m%d-%H%M%S)" || true
    sed -e "s#root /path/to/cwhpanel/public;#root ${LIVE_DIR}/public;#" \
        -e "s#server_name _;#server_name ${PANEL_HOST};#" \
        -e "s#\${PANEL_HOST}#${PANEL_HOST}#g" \
        "$here/deploy/nginx-panel.conf.example" > /etc/nginx/conf.d/panel.conf
    step "nginx panel vhost installed (server_name $PANEL_HOST)"
fi
patch_panel_static && step "static-asset expires applied to panel vhost"
# The stock welcome-page vhost would collide on `default_server` (emerg).
[ -f /etc/nginx/conf.d/default.conf ] && mv /etc/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf.disabled || true
if nginx -t 2>/dev/null; then
    systemctl enable --now nginx >/dev/null 2>&1 || true
    systemctl reload-or-restart nginx >/dev/null 2>&1 || true
    step "nginx serving the panel on port 80"
else
    warn "nginx -t failed; fix /etc/nginx/conf.d/panel.conf then reload nginx"
fi

# -- Firewall: the panel + LE HTTP-01 need 80/443.
if systemctl is-active firewalld >/dev/null 2>&1; then
    # Web + mail + DNS + FTP (so a fresh box actually receives mail/DNS/FTP,
    # not just HTTP). Passive FTP range matches the vsftpd template
    # (pasv_min_port=30000/pasv_max_port=30100).
    firewall-cmd --permanent         --add-service=http --add-service=https         --add-service=smtp --add-service=smtps --add-service=submission         --add-service=imaps --add-service=pop3s --add-service=dns --add-service=ftp         >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-port=30000-30100/tcp >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
    step "firewall opened for http/https + mail/dns/ftp"
fi

# -- APP_URL follows the chosen host. Explicit choices (flag/prompt) always
# win; automatic ones only fill an absent/dev-default value, never an
# operator-set URL.
set_app_url() {
    local file="$1" url="$2"
    if grep -qE '^APP_URL=' "$file" 2>/dev/null; then
        sed -i "s#^APP_URL=.*#APP_URL=${url}#" "$file"
    else
        echo "APP_URL=${url}" >> "$file"
    fi
}
if [ "$PANEL_HOST" != "_" ]; then
    if [ "$PANEL_HOST_EXPLICIT" = "1" ]; then
        set_app_url "$here/.env" "http://${PANEL_HOST}/"
        set_app_url "$LIVE_DIR/.env" "http://${PANEL_HOST}/"
        step "APP_URL set to http://${PANEL_HOST}/ (step 7b upgrades to https)"
    elif ! grep -qE '^APP_URL=https?://[^/]+' "$here/.env" || grep -qE '^APP_URL=http://127\.0\.0\.1' "$here/.env"; then
        set_app_url "$here/.env" "http://${PANEL_HOST}/"
        set_app_url "$LIVE_DIR/.env" "http://${PANEL_HOST}/"
        step "APP_URL set to http://${PANEL_HOST}/"
    fi
fi
chmod 600 "$here/.env" 2>/dev/null || true
chown root:cwhpanel-panel "$LIVE_DIR/.env" 2>/dev/null || true
chmod 640 "$LIVE_DIR/.env" 2>/dev/null || true

# ---------------------------------------------------------------------------
export PANEL_GROUP="cwhpanel-panel"
log "7c/7 phpMyAdmin"
if [ -x "$here/scripts/install-phpmyadmin.sh" ]; then
    ( cd "$here" && PANEL_GROUP=cwhpanel-panel bash scripts/install-phpmyadmin.sh ) || warn "phpMyAdmin install failed (continuing)"
    step "phpMyAdmin installed at /usr/share/phpMyAdmin"
fi

# ---------------------------------------------------------------------------
log "7d/7 Roundcube webmail"
# Turn-up never creates /opt/cwhpanel/.env (the live tree is synced by hand),
# so fall back to the repo .env that step 7 just populated (DB creds +
# MAIL_MASTER_PASSWORD). An explicit PANEL_ENV still wins.
RC_ENV="${PANEL_ENV:-/opt/cwhpanel/.env}"
[ -f "$RC_ENV" ] || RC_ENV="$here/.env"
if [ -x "$here/scripts/install-roundcube.sh" ]; then
    ( cd "$here" && PANEL_ENV="$RC_ENV" PANEL_GROUP=cwhpanel-panel bash scripts/install-roundcube.sh ) || warn "Roundcube install failed (continuing)"
    step "Roundcube installed at /usr/share/roundcubemail"
fi

# ---------------------------------------------------------------------------
log "7b/7 Server hostname DNS (SPF/DKIM/DMARC in the local PowerDNS)"
# Seed the hostname's zone + records (A/AAAA, mail, MX, SPF, DMARC, DKIM, NS)
# into the PowerDNS this box already runs, so host/cron/root mail authenticates
# without an external DNS provider. Best-effort; never fails turn-up.
if [[ -f "$here/scripts/hostname-dns.php" ]]; then
    ( cd "$here" && php scripts/hostname-dns.php ) \
        && step "hostname DNS seeded (SPF/DKIM/DMARC)" \
        || warn "hostname DNS not seeded (re-run scripts/hostname-dns.php)"
fi

# ---------------------------------------------------------------------------
log "7c/7 Server hostname TLS (panel + mail via Let's Encrypt)"
# Detects the FQDN, issues a cert via HTTP-01, serves the panel on 443
# (http→https, ACME path exempt), points Postfix/Dovecot at it, sets APP_URL
# to https://, and installs a weekly renewal cron. Skips cleanly when the
# hostname is unusable; never fails turn-up (warns instead).
if [[ -f "$here/scripts/provision-hostname-tls.php" ]]; then
    ( cd "$here" && php scripts/provision-hostname-tls.php ) \
        && step "hostname TLS provisioned" \
        || warn "hostname TLS not provisioned (see output above; re-run scripts/provision-hostname-tls.php once DNS is ready)"
fi

# -- Admin panel port (split panels): customer on the public host, admin on
# its own TLS port (PANEL_ADMIN_PORT, default 8443). Adds the listen lines to
# the panel vhost (next to 443, requires the hostname cert) and opens firewalld.
ADMIN_PORT_V="$(env_get PANEL_ADMIN_PORT 8443)"
for envf in "$here/.env" "$LIVE_DIR/.env"; do
    [ -f "$envf" ] || continue
    if grep -qE '^PANEL_ADMIN_PORT=' "$envf"; then
        sed -i "s#^PANEL_ADMIN_PORT=.*#PANEL_ADMIN_PORT=${ADMIN_PORT_V}#" "$envf"
    else
        echo "PANEL_ADMIN_PORT=${ADMIN_PORT_V}" >> "$envf"
    fi
done
step "PANEL_ADMIN_PORT=${ADMIN_PORT_V} written to .env (source + live)"
if [ -f /etc/nginx/conf.d/panel.conf ] && [ "$ADMIN_PORT_V" != "80" ] && [ "$ADMIN_PORT_V" != "443" ]; then
    if ! grep -qE "listen ${ADMIN_PORT_V}( ssl)?;" /etc/nginx/conf.d/panel.conf; then
        if grep -q "listen 443 ssl" /etc/nginx/conf.d/panel.conf && [ -s /etc/cwhpanel/ssl/hostname/fullchain.pem ]; then
            sed -i "0,/listen 443 ssl/s//listen ${ADMIN_PORT_V} ssl;\n    listen [::]:${ADMIN_PORT_V} ssl;\n    listen 443 ssl/" /etc/nginx/conf.d/panel.conf
            step "admin panel port ${ADMIN_PORT_V} added to nginx vhost (TLS)"
        else
            # No certificate yet (IP-only install, or DNS not live): serve the
            # admin panel over plain HTTP on its own port so the operator can
            # sign in and finish provisioning. areaUrl() follows APP_URL's
            # scheme, so the redirect targets http://<host>:<port>/login.
            # provision-hostname-tls.php replaces these lines with the ssl pair
            # once a certificate exists.
            sed -i "0,/listen \[::\]:80;/s//listen [::]:80;\n    listen ${ADMIN_PORT_V};\n    listen [::]:${ADMIN_PORT_V};/" /etc/nginx/conf.d/panel.conf
            step "admin panel port ${ADMIN_PORT_V} added (plain HTTP until TLS is provisioned)"
        fi
    fi
    if systemctl is-active firewalld >/dev/null 2>&1; then
        firewall-cmd --permanent --add-port=${ADMIN_PORT_V}/tcp >/dev/null 2>&1 || true
        firewall-cmd --reload >/dev/null 2>&1 || true
        step "firewall opened for admin port ${ADMIN_PORT_V}/tcp"
    fi
    if nginx -t 2>/dev/null; then
        systemctl reload-or-restart nginx >/dev/null 2>&1 || true
    else
        warn "nginx -t failed after admin-port addition"
    fi
fi

# ---------------------------------------------------------------------------
FINAL_URL="$(env_get APP_URL "http://localhost/")"
[ "$here" = "$LIVE_DIR" ] || cp -a "$LIVE_DIR/.env" "$here/.env" 2>/dev/null || true
cat <<EOF

Done. cwhpanel is turned up.

  Panel    : $FINAL_URL (nginx + php-fpm pool 'panel', live tree /opt/cwhpanel)
  Admin    : https://$(echo "$FINAL_URL" | sed -E 's#https?://##; s#/.*##'):$ADMIN_PORT_V (TLS; customer login on the public port)
  Database : $DB_DATABASE (user $DB_USERNAME)
  PowerDNS : $PDNS_DB (gmysql, 127.0.0.1)
  Services :
    cwhpanel-agent.service        (privileged provisioning agent)
    cwhpanel-worker.service       (background job/webhook runner)
    cwhpanel-mail-policy.service  (outbound mail rate limiter)
    mariadb / pdns / postfix / dovecot / clamd / vsftpd / crond

Next steps:
  - Log in with ADMIN_EMAIL / ADMIN_PASSWORD from .env.
    (Demo customer/package/account: SEED_DEMO=1 php scripts/seed.php — not
     seeded by default, so a fresh install is production-clean.)
  - For DNS hosting, point a real domain's NS records at this host's public IP.
  - If the panel domain's DNS is not live yet, re-run
    scripts/provision-hostname-tls.php once it is (upgrades the panel to https).
  - Run scripts/failure-test.sh + scripts/isolation-test.sh to validate.

EOF