#!/usr/bin/env bash
#
# cwhpanel uninstaller — totally remove the control panel and its dependencies.
#
# Reverses what install.sh + scripts/turn-up.sh (+ the provisioners) put on the
# host: panel services, hosting accounts, nginx/php-fpm/mail/dns configs,
# databases, firewall rules, SELinux policy, installed trees, and (optionally)
# the hosting-stack DNF packages.
#
# Idempotent: safe to re-run; every step skips what is already gone.
# Destructive: dropping databases, deleting system users, erasing packages.
# A timestamped backup of the touched system configs is saved to
# /root/cwhpanel-uninstall-backup/<ts>/ before anything is removed.
#
# Usage:
#   ./uninstall.sh                  # interactive (asks for confirmation)
#   ./uninstall.sh --yes            # non-interactive (assume yes)
#   ./uninstall.sh --dry-run        # print what would be done, change nothing
#
# Options:
#   --yes, -y            assume yes to the confirmation prompt
#   --dry-run            print actions only, change nothing (root not required)
#   --keep-packages      do not dnf-erase the hosting stack
#   --keep-databases     do not drop MariaDB databases/users
#   --keep-accounts      do not delete hosting system users/homes/jails
#   --purge-backups      also delete /var/backups/* (kept by default)
#   --live-dir DIR       live panel copy (default /opt/cwhpanel)
#   --help, -h           this help
#
# Never removes: curl, openssl, git, python3, epel-release, opencode-web,
# /usr/local/bin/node (may be the admin's own). See the summary at the end.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIVE_DIR="/opt/cwhpanel"
YES=0
DRY=0
KEEP_PKGS=0
KEEP_DB=0
KEEP_ACCOUNTS=0
PURGE_BACKUPS=0
FORCE_HOME_SWEEP=0

for arg in "$@"; do
    case "$arg" in
        --yes|-y) YES=1 ;;
        --dry-run) DRY=1 ;;
        --keep-packages) KEEP_PKGS=1 ;;
        --keep-databases) KEEP_DB=1 ;;
        --keep-accounts) KEEP_ACCOUNTS=1 ;;
        --force-home-sweep) FORCE_HOME_SWEEP=1 ;;
        --purge-backups) PURGE_BACKUPS=1 ;;
        --live-dir=*) LIVE_DIR="${arg#*=}" ;;
        --help|-h)
            sed -n '2,30p' "$0"
            exit 0
            ;;
        *) echo "Unknown argument: $arg (see --help)" >&2; exit 2 ;;
    esac
done

if [ "$DRY" = "0" ] && [ "$(id -u)" != "0" ]; then
    echo "ERROR: must run as root (or use --dry-run)" >&2
    exit 1
fi

log()  { printf '\n==> %s\n' "$*"; }
step() { printf '    - %s\n' "$*"; }
warn() { printf '    ! WARN: %s\n' "$*"; }
command -v mysql >/dev/null 2>&1 || warn "mysql client missing: database steps will skip themselves"

# run: execute (or echo in dry-run). Never fails the script.
run() {
    if [ "$DRY" = "1" ]; then
        printf '    [dry-run] %s\n' "$*"
        return 0
    fi
    "$@" >/dev/null 2>&1 || true
}
# run_show: reserved for listing steps (currently unused).

# ---------------------------------------------------------------------------
# Read panel DB credentials early (needed before /opt/cwhpanel is removed).
# ---------------------------------------------------------------------------
ENV_FILE=""
[ -f "$LIVE_DIR/.env" ] && ENV_FILE="$LIVE_DIR/.env"
[ -z "$ENV_FILE" ] && [ -f "$HERE/.env" ] && ENV_FILE="$HERE/.env"
env_get() {
    local key="$1" default="${2:-}"
    local v=""
    [ -n "$ENV_FILE" ] && v="$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- || true)"
    printf '%s' "${v:-$default}"
}
DB_DATABASE="$(env_get DB_DATABASE cwhpanel)"
DB_USERNAME="$(env_get DB_USERNAME cwhpanel)"
DB_PASSWORD="$(env_get DB_PASSWORD)"

mysql_root() {
    if mysql -N -e 'SELECT 1' >/dev/null 2>&1; then
        mysql "$@"
    else
        mysql -uroot "$@"
    fi
}

# ---------------------------------------------------------------------------
# Confirm.
# ---------------------------------------------------------------------------
cat <<EOF

cwhpanel uninstaller — TOTAL REMOVAL
  repo dir : $HERE
  live dir : $LIVE_DIR
  panel DB : $DB_DATABASE
  keep packages : $KEEP_PKGS | keep databases : $KEEP_DB | keep accounts : $KEEP_ACCOUNTS | purge backups : $PURGE_BACKUPS
  dry run       : $DRY

This will stop panel services, delete hosting accounts (system users, homes,
jails, PHP masters), remove nginx/php-fpm/postfix/dovecot/pdns configs, drop
databases, remove firewall rules + SELinux policy, delete /opt/cwhpanel and
the agent binary$([ "$KEEP_PKGS" = "0" ] && echo " AND dnf-erase the hosting stack" || echo ", keeping DNF packages").
EOF
if [ "$DRY" = "0" ] && [ "$YES" != "1" ]; then
    if [ -t 0 ]; then
        printf 'Type YES to continue: '
        read -r ans
        [ "${ans:-}" = "YES" ] || { echo "Aborted."; exit 1; }
    else
        echo "Non-interactive shell: pass --yes to confirm." >&2
        exit 1
    fi
fi

BACKUP_DIR="/root/cwhpanel-uninstall-backup/$(date +%Y%m%d-%H%M%S)"

# ---------------------------------------------------------------------------
log "0/12 Back up touched system configs to $BACKUP_DIR"
# ---------------------------------------------------------------------------
if [ "$DRY" = "1" ]; then
    step "[dry-run] would snapshot configs to $BACKUP_DIR"
else
    mkdir -p "$BACKUP_DIR"
    for f in /etc/pdns/pdns.conf /etc/postfix/main.cf /etc/postfix/master.cf \
             /etc/dovecot/conf.d/99-cwhpanel.conf /etc/dovecot/conf.d/99-cwhpanel-hostname.conf \
             /etc/vsftpd/vsftpd.conf /etc/clamd.d/scan.conf /etc/ssh/sshd_config \
             /etc/mail/spamassassin/local.cf /etc/nginx/conf.d/panel.conf /etc/fstab \
             /etc/php-fpm-panel.d/panel.conf /etc/php-fpm-panel.conf /etc/systemd/system/php-fpm-panel.service; do
        [ -f "$f" ] && { mkdir -p "$BACKUP_DIR/$(dirname "$f")"; cp -a "$f" "$BACKUP_DIR/$f" 2>/dev/null || true; }
    done
    [ -n "$ENV_FILE" ] && cp -a "$ENV_FILE" "$BACKUP_DIR/panel.env" 2>/dev/null || true
    step "snapshot saved"
fi

# ---------------------------------------------------------------------------
log "1/12 Stop + disable panel services and transient account/app units"
# ---------------------------------------------------------------------------
for u in cwhpanel-mail-policy.service cwhpanel-worker.service cwhpanel-agent.service cwhpanel-boot-restore.service; do
    run systemctl stop "$u"
    run systemctl disable "$u"
done
# opendkim (DKIM milter) is installed + enabled by turn-up for the panel's
# signing tables. Stop it before its config is removed — otherwise the host
# boots into a failed unit and the next install inherits the failed state.
run systemctl stop opendkim.service
run systemctl disable opendkim.service

# PHP-FPM daemons MUST stop before accounts are deleted: the pooled master
# respawns pool workers AS THE ACCOUNT USER, so `userdel` always fails with
# "user is currently used by process" while the pool exists in its runtime
# config. (Per-account masters are stopped by the cwh-php-* sweep below.)
for fpm in php-fpm php-fpm-panel php74-php-fpm php80-php-fpm php81-php-fpm \
           php82-php-fpm php83-php-fpm php84-php-fpm php85-php-fpm php86-php-fpm; do
    run systemctl stop "$fpm"
done

# Transient/orphaned per-account units (workers, apps, PHP masters).
for pat in 'cwh-php-*' 'cwh-nginx-*' 'cwh-app-*'; do
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would stop units matching $pat"
    else
        for u in $(systemctl list-units --all --no-legend "$pat" 2>/dev/null | awk '{print $1}'); do
            systemctl stop "$u" >/dev/null 2>&1 || true
        done
    fi
done
run systemctl daemon-reload
step "panel services stopped"

# ---------------------------------------------------------------------------
log "2/12 Enumerate hosting accounts"
# ---------------------------------------------------------------------------
ACCOUNTS=""
if [ "$KEEP_ACCOUNTS" = "1" ]; then
    step "keeping accounts (--keep-accounts), skipping enumeration"
elif command -v mysql >/dev/null 2>&1 && mysql_root -N -e "USE \`$DB_DATABASE\`; SELECT 1" >/dev/null 2>&1; then
    ACCOUNTS="$(mysql_root -N -e "USE \`$DB_DATABASE\`; SELECT username FROM accounts" 2>/dev/null || true)"
fi
if [ -z "$ACCOUNTS" ] && [ "$KEEP_ACCOUNTS" = "0" ]; then
    # Fallback when the panel DB is unreachable: only members of the panel's
    # jail group (jailed hosting accounts), never an arbitrary /home/* sweep —
    # this box may host unrelated local users. --force-home-sweep opts back in.
    if getent group sshjailed >/dev/null 2>&1; then
        ACCOUNTS="$(getent group sshjailed | cut -d: -f4 | tr ',' ' ' || true)"
        [ -n "$ACCOUNTS" ] && step "accounts discovered via the sshjailed group"
    fi
    if [ -z "$ACCOUNTS" ] && [ "$FORCE_HOME_SWEEP" = "1" ]; then
        for d in /home/*; do
            u="$(basename "$d")"
            id "$u" >/dev/null 2>&1 && ACCOUNTS="$ACCOUNTS $u"
        done
        step "WARNING: sweeping every /home/* user (--force-home-sweep)"
    fi
    if [ -z "$ACCOUNTS" ]; then
        warn "no accounts discovered (DB unreachable, no sshjailed members)."
        warn "Re-run with --force-home-sweep to sweep all /home/* users, or --keep-accounts."
    fi
fi
ACCOUNTS="$(echo "$ACCOUNTS" | tr ' ' '\n' | grep -E '^[a-z_][a-z0-9_-]*$' | sort -u || true)"
if [ -z "$ACCOUNTS" ]; then
    step "no hosting accounts found"
else
    step "accounts: $(echo "$ACCOUNTS" | tr '\n' ' ')"
fi

# ---------------------------------------------------------------------------
# ---------------------------------------------------------------------------
# Remove one hosting account completely.
#
# Lingering user managers are the trap here: the panel enables linger so the
# account's delegated systemd user manager sits in its cgroup slice, and systemd
# keeps/user@<uid>.service alive across kills. `userdel` then fails with "user
# is currently used by process", and the old code deleted the HOME anyway —
# leaving a half-deleted account (passwd entry, no home) that made every later
# "clean" reinstall dirty.
#
# Order matters: disable linger -> stop the user manager -> kill -> userdel,
# retried, and NEVER delete the home of a user that survived.
remove_account_user() {
    local u="$1" uid attempt
    uid="$(id -u "$u" 2>/dev/null || true)"
    [ -n "$uid" ] || return 0

    if command -v loginctl >/dev/null 2>&1; then
        loginctl disable-linger "$u" >/dev/null 2>&1 || true
    fi
    rm -f "/var/lib/systemd/linger/$u" 2>/dev/null || true
    systemctl stop "user@${uid}.service" >/dev/null 2>&1 || true
    pkill -u "$u" >/dev/null 2>&1 || true
    sleep 1

    for attempt in 1 2 3; do
        if userdel -r "$u" >/dev/null 2>&1 || userdel "$u" >/dev/null 2>&1; then
            break
        fi
        systemctl stop "user@${uid}.service" >/dev/null 2>&1 || true
        pkill -9 -u "$u" >/dev/null 2>&1 || true
        sleep 1
    done

    if id "$u" >/dev/null 2>&1; then
        # Last resort: a daemon outside the panel's unit list may be respawning
        # workers as this user (pooled php-fpm is the classic one). Stop the
        # engines, then retry once.
        for fpm in php-fpm php-fpm-panel php74-php-fpm php80-php-fpm php81-php-fpm \
                   php82-php-fpm php83-php-fpm php84-php-fpm php85-php-fpm php86-php-fpm; do
            systemctl stop "$fpm" >/dev/null 2>&1 || true
        done
        pkill -9 -u "$u" >/dev/null 2>&1 || true
        sleep 1
        userdel -r "$u" >/dev/null 2>&1 || userdel "$u" >/dev/null 2>&1 || true
    fi
    if id "$u" >/dev/null 2>&1; then
        warn "account $u still exists after 3 userdel attempts — remove manually"
        return 1
    fi

    groupdel "$u" >/dev/null 2>&1 || true
    rm -rf "/home/$u" "/jail/$u" "/run/user/$uid" 2>/dev/null || true
    systemctl reset-failed "user@${uid}.service" >/dev/null 2>&1 || true
    return 0
}

log "3/12 Delete hosting accounts (jail, PHP masters, firewall, slice, user)"
# ---------------------------------------------------------------------------
if [ "$KEEP_ACCOUNTS" = "1" ]; then
    step "skipped (--keep-accounts)"
else
    for u in $ACCOUNTS; do
        # 3a. Lazy-umount any live jail binds, then remove the jail tree.
        if [ "$DRY" = "1" ]; then
            step "[dry-run] would umount /jail/$u binds + rm -rf /jail/$u"
        else
            grep -E " /jail/${u}(/| )" /proc/mounts 2>/dev/null | awk '{print $2}' | sort -r | \
                while read -r m; do umount -l "$m" >/dev/null 2>&1 || true; done || true
            rm -rf "/jail/$u" 2>/dev/null || true
            # Strip this jail's tagged fstab lines.
            [ -f /etc/fstab ] && sed -i "/cwhpanel-jail:${u}[[:space:]]*$/d; /# cwhpanel-jail:${u}$/d" /etc/fstab
            # Per-account cron spools (both historical names).
            rm -f "/var/spool/cron/$u" "/var/spool/cron/cwh-$u" 2>/dev/null || true
            # Dedicated panel IPs: account IPv6 + assigned pool IPv4s.
            # Usernames are creation-validated [a-z0-9_-], safe to interpolate.
            for ip6 in $(mysql_root -N -e "USE \`$DB_DATABASE\`; SELECT ipv6_address FROM accounts WHERE username='$u' AND ipv6_address IS NOT NULL AND ipv6_address != ''" 2>/dev/null || true); do
                ip -6 addr del "$ip6/64" dev eth0 >/dev/null 2>&1 || true
            done
        fi
        # 3b. PHP masters: stop/disable units, remove unit files + /etc/cwhpanel trees.
        for ver in "" "-php74" "-php80" "-php81" "-php82" "-php83"; do
            svc="cwh-php-${u}${ver}"
            run systemctl stop "$svc"
            run systemctl disable "$svc"
            run rm -f "/etc/systemd/system/${svc}.service"
            run rm -rf "/etc/cwhpanel/php/${u}${ver}"
        done
        run rm -f "/etc/php-fpm.d/${u}.conf"
        # Versioned pools live under each Remi tree. Leaving them behind makes
        # the NEXT install's php-fpm daemons fail with "cannot get uid for user
        # '<account>'" (the account is gone) — three dead PHP versions and
        # failed units on a fresh box (bitten).
        for pv in 74 80 81 82 83 84 85 86; do
            run rm -f "/etc/opt/remi/php${pv}/php-fpm.d/${u}.conf"
        done
        # 3c. Per-UID SMTP egress direct rules (mirror of agent smtp_egress_rules).
        if [ "$DRY" = "1" ]; then
            step "[dry-run] would remove firewall direct egress rules for uid $u"
        else
            uid="$(id -u "$u" 2>/dev/null || true)"
            if [ -n "$uid" ] && command -v firewall-cmd >/dev/null 2>&1; then
                for fam_lb in "ipv4 127.0.0.0/8 icmp-port-unreachable" "ipv6 ::1/128 icmp6-port-unreachable"; do
                    # shellcheck disable=SC2086
                    set -- $fam_lb
                    firewall-cmd --permanent --direct --remove-rule "$1" filter OUTPUT 0 \
                        -m owner --uid-owner "$uid" -d "$2" -p tcp -m multiport \
                        --dports 25,465,587,2525 -j ACCEPT >/dev/null 2>&1 || true
                    firewall-cmd --permanent --direct --remove-rule "$1" filter OUTPUT 1 \
                        -m owner --uid-owner "$uid" -p tcp -m multiport \
                        --dports 25,465,587,2525 -j REJECT --reject-with "$3" >/dev/null 2>&1 || true
                done
            fi
            # cgroup slice unit.
            rm -f "/etc/systemd/system/${u}.slice" 2>/dev/null || true
            remove_account_user "$u" || true
        fi
        # 3d. Per-site nginx logs for this account.
        run rm -f /var/log/nginx/"${u}"-*.log
        step "removed account: $u"
    done
    # Fallback sweep: panel-provisioned homes whose account row was missing
    # (drifted DB, a user created by an older build, or a failed deletion).
    # Markers only — a plain /home user is never touched.
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would sweep /home/* panel accounts missing from the DB list"
    else
        for home in /home/*; do
            [ -d "$home" ] || continue
            u="$(basename "$home")"
            id "$u" >/dev/null 2>&1 || continue
            uid="$(id -u "$u" 2>/dev/null || echo 0)"
            [ "$uid" -ge 1000 ] 2>/dev/null || continue
            if [ -d "$home/.php" ] || [ -d "$home/nginx/conf.d" ] || [ -d "$home/public_html" ] \
                || compgen -G "$home/*/public_html" >/dev/null 2>&1; then
                step "sweeping orphan panel account: $u"
                systemctl stop "cwh-nginx-$u" >/dev/null 2>&1 || true
                remove_account_user "$u" || true
            fi
        done

        # FTP logins: every ftp_accounts row is a dedicated system user that
        # SHARES the account's uid and lives under the account's home
        # (`/home/<account>/ftp/<login>`), so the /home/* sweep above never
        # sees it. Any uid>=1000 user whose home is inside /home/ but is not
        # /home/<its own name> is a panel-created login on this kind of host.
        while read -r u _x _uid home; do
            case "$home" in
                /home/*) ;;
                *) continue ;;
            esac
            [ "$home" = "/home/$u" ] && continue
            step "sweeping panel login user: $u"
            remove_account_user "$u" || true
        done < <(getent passwd | awk -F: '$3>=1000 && $3<65000 {print $1, "*", $3, $6}')
    fi

    # Dedicated pool IPv4s still bound (assigned rows only; free rows were
    # never necessarily bound, and del is tolerant anyway).
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would unbind assigned pool IPv4s"
    else
        mysql_root -N -e "USE \`$DB_DATABASE\`; SELECT CONCAT(address,'/',COALESCE(prefixlen,32)) FROM ip_pool WHERE status='assigned'" 2>/dev/null | \
            while read -r rec; do
                [ -n "$rec" ] && ip -4 addr del "$rec" dev eth0 >/dev/null 2>&1 || true
            done || true
    fi
    # Jail group + sshd Match block (empty without jails).
    run groupdel sshjailed
    # Remove /jail itself once all account jails are gone (rmdir: only if empty).
    run rmdir /jail
    if [ -f /etc/ssh/sshd_config ] && grep -q "BEGIN cwhpanel-jail" /etc/ssh/sshd_config; then
        if [ "$DRY" = "1" ]; then
            step "[dry-run] would strip the cwhpanel-jail block from sshd_config"
        else
            sed -i '/# BEGIN cwhpanel-jail/,/# END cwhpanel-jail/d' /etc/ssh/sshd_config
            /usr/sbin/sshd -t 2>/dev/null && systemctl reload-or-restart sshd >/dev/null 2>&1 || true
            step "sshd jail block removed"
        fi
    fi
    run systemctl daemon-reload
fi

# ---------------------------------------------------------------------------
log "4/12 Remove nginx configs (vhosts, rate-limit, WAF, panel)"
# ---------------------------------------------------------------------------
run rm -f /etc/nginx/conf.d/hosting-*.conf /etc/nginx/conf.d/hosting-*.conf.bak-*
run rm -f /etc/nginx/conf.d/cwhpanel-ratelimit.conf
run rm -f /etc/nginx/conf.d/cwhpanel-modsecurity.conf
run rm -f /usr/local/bin/cwhpanel-cron-run
run rm -f /etc/modsecurity/cwhpanel-crs.conf
run rm -f /etc/nginx/conf.d/panel.conf /etc/nginx/conf.d/panel.conf.bak-*
run rm -f /var/log/nginx/modsec_audit
if [ "$DRY" = "1" ]; then
    step "[dry-run] would run nginx -t + reload (if nginx installed)"
elif command -v nginx >/dev/null 2>&1; then
    nginx -t 2>/dev/null && systemctl reload-or-restart nginx >/dev/null 2>&1 || true
    step "nginx reloaded"
fi

# ---------------------------------------------------------------------------
log "5/12 Remove PHP-FPM pools (/etc/cwhpanel, legacy + panel pools)"
# ---------------------------------------------------------------------------
run rm -rf /etc/cwhpanel
run rm -f /etc/php-fpm.d/panel.conf
run systemctl disable --now php-fpm-panel.service 2>/dev/null || true
run rm -f /etc/systemd/system/php-fpm-panel.service
run rm -rf /etc/php-fpm-panel.d /etc/php-fpm-panel.conf
run systemctl daemon-reload
run rm -f /etc/systemd/system/php-fpm.service.d/cwhpanel-sockets.conf
run rm -f /var/log/php-fpm/panel.log
if [ "$DRY" = "1" ]; then
    step "[dry-run] would reload php-fpm + Remi daemons (if installed)"
elif command -v php-fpm >/dev/null 2>&1; then
    systemctl reload-or-restart php-fpm >/dev/null 2>&1 || true
    for v in 74 80 81 82 83; do
        systemctl reload-or-restart "php${v}-php-fpm" >/dev/null 2>&1 || true
    done
    step "php-fpm reloaded"
fi

# ---------------------------------------------------------------------------
log "6/12 Restore postfix/dovecot/spamassassin/vsftpd/clamd configs"
# ---------------------------------------------------------------------------
if command -v postconf >/dev/null 2>&1; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would postconf -X panel params + rm virtual/sender/vmail maps"
    else
        for p in smtpd_sasl_type smtpd_sasl_path smtpd_sasl_auth_enable \
                 smtpd_tls_auth_only broken_sasl_auth_clients \
                 smtpd_relay_restrictions smtpd_recipient_restrictions \
                 smtpd_sender_login_maps smtpd_sender_restrictions \
                 virtual_transport virtual_mailbox_domains virtual_alias_maps \
                 smtpd_end_of_data_restrictions smtpd_policy_service_max_idle \
                 smtpd_milters non_smtpd_milters milter_default_action \
                 smtpd_tls_cert_file smtpd_tls_key_file; do
            postconf -X "$p" >/dev/null 2>&1 || true
        done
        rm -f /etc/postfix/virtual.cwhpanel /etc/postfix/virtual.cwhpanel.db \
              /etc/postfix/sender_logins /etc/postfix/sender_logins.db \
              /etc/postfix/vmaildomains /etc/postfix/vmaildomains.db
        systemctl reload-or-restart postfix >/dev/null 2>&1 || true
        step "postfix panel params/maps removed"
    fi
else
    step "postfix not installed, skipping postconf reset"
fi
run rm -f /etc/dovecot/conf.d/99-cwhpanel.conf /etc/dovecot/conf.d/99-cwhpanel-hostname.conf
run rm -f /etc/dovecot/cwhpanel-passwd /etc/dovecot/cwhpanel-users /etc/dovecot/cwhpanel-master-users
if [ "$DRY" = "0" ] && command -v dovecot >/dev/null 2>&1; then
    systemctl reload-or-restart dovecot >/dev/null 2>&1 || true
fi
# SpamAssassin: the panel rewrites local.cf wholesale — back up (step 0) and drop it.
run rm -f /etc/mail/spamassassin/local.cf
# vsftpd: strip only the hostname-TLS block; the base file was panel-written and
# cannot be restored to stock — it goes away with package erase below.
if [ -f /etc/vsftpd/vsftpd.conf ] && grep -q "BEGIN cwhpanel-hostname-tls" /etc/vsftpd/vsftpd.conf; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would strip the cwhpanel-hostname-tls block from vsftpd.conf"
    else
        sed -i '/# BEGIN cwhpanel-hostname-tls/,/# END cwhpanel-hostname-tls/d' /etc/vsftpd/vsftpd.conf
        systemctl reload-or-restart vsftpd >/dev/null 2>&1 || true
    fi
fi
# ClamAV: drop the panel-appended socket block (it sits at EOF).
if [ -f /etc/clamd.d/scan.conf ] && grep -q "cwhpanel-managed" /etc/clamd.d/scan.conf; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would strip the cwhpanel-managed block from scan.conf"
    else
        sed -i '/# cwhpanel-managed/,$d' /etc/clamd.d/scan.conf
        sed -i -e :a -e '/^\n*$/{$d;N;ba' -e '}' /etc/clamd.d/scan.conf
    fi
fi
run rm -f /usr/lib/tmpfiles.d/cwhpanel-clamd.conf

# ---------------------------------------------------------------------------
log "7/12 Drop MariaDB databases + users"
# ---------------------------------------------------------------------------
if [ "$KEEP_DB" = "1" ]; then
    step "skipped (--keep-databases)"
elif ! command -v mysql >/dev/null 2>&1; then
    step "mysql client missing, skipping"
else
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would drop all non-system databases + non-system users"
    else
        DBS="$(mysql_root -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql','information_schema','performance_schema','sys');" 2>/dev/null || true)"
        for db in $DBS; do
            mysql_root -e "DROP DATABASE IF EXISTS \`$db\`;" >/dev/null 2>&1 || warn "could not drop database $db"
            step "dropped database: $db"
        done
        USERS="$(mysql_root -N -e "SELECT user, host FROM mysql.user WHERE user NOT IN ('root','mysql','mariadb.sys');" 2>/dev/null || true)"
        echo "$USERS" | while IFS=$'\t' read -r dbuser dbhost; do
            [ -n "${dbuser:-}" ] && [ -n "${dbhost:-}" ] || continue
            mysql_root -e "DROP USER IF EXISTS '${dbuser}'@'${dbhost}';" >/dev/null 2>&1 || warn "could not drop user '${dbuser}'@'${dbhost}'"
            step "dropped user: '${dbuser}'@'${dbhost}'"
        done
        mysql_root -e "FLUSH PRIVILEGES;" >/dev/null 2>&1 || true
    fi
fi

# ---------------------------------------------------------------------------
log "8/12 Remove firewalld rules added by the panel"
# ---------------------------------------------------------------------------
if command -v firewall-cmd >/dev/null 2>&1; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would remove 3306/pasv rich rules + uid-owner direct rules"
    else
        if systemctl is-active firewalld >/dev/null 2>&1; then
            # Remote-MySQL accepts + FTP pasv leftovers (port-based). Reject/block
            # rules are deliberately LEFT in place (they cost nothing, stay safe).
            firewall-cmd --permanent --list-rich-rules 2>/dev/null | grep -E 'port port="(3306|30000-30100)"' | \
                while read -r rule; do
                    firewall-cmd --permanent --remove-rich-rule="$rule" >/dev/null 2>&1 || true
                done || true
            # Any uid-owner direct rules mentioning the SMTP ports.
            firewall-cmd --permanent --direct --get-all-rules 2>/dev/null | grep -E 'uid-owner|25,465,587,2525' | \
                while read -r line; do
                    # shellcheck disable=SC2086
                    firewall-cmd --permanent --direct --remove-rule $line >/dev/null 2>&1 || true
                done || true
            firewall-cmd --reload >/dev/null 2>&1 || true
            step "firewalld panel rules removed (reject/block rules left intact)"
            # http/https were opened for the panel front + LE; close them when
            # the web stack goes away too (nothing listens anymore).
            if [ "$KEEP_PKGS" = "0" ]; then
                firewall-cmd --permanent --remove-service=http --remove-service=https >/dev/null 2>&1 || true
                firewall-cmd --reload >/dev/null 2>&1 || true
            fi
        else
            step "firewalld not running, skipping"
        fi
    fi
else
    step "firewall-cmd missing, skipping"
fi

# ---------------------------------------------------------------------------
log "9/12 Remove SELinux policy + restore hardened defaults"
# ---------------------------------------------------------------------------
if [ -x "$HERE/agent/selinux/setup.sh" ]; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would run agent/selinux/setup.sh --unload"
    elif ( cd "$HERE/agent/selinux" && bash setup.sh --unload ); then
        step "SELinux module + fcontexts + port labels removed"
    else
        warn "SELinux unload left remnants (see above); re-run agent/selinux/setup.sh --unload manually"
    fi
elif [ -f "$HERE/agent/selinux/apply-contexts.sh" ]; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would run agent/selinux/apply-contexts.sh --unload"
    elif bash "$HERE/agent/selinux/apply-contexts.sh" --unload; then
        step "SELinux modules + fcontexts + port labels removed"
    else
        warn "SELinux unload left remnants (see above); re-run agent/selinux/apply-contexts.sh --unload manually"
    fi
else
    run semodule -r cwhpanel
fi
# ssh_chroot_rw_homedirs was enabled purely for the jails; the db/httpd/clamav
# booleans are generic and may serve other software — left as-is (see summary).
run setsebool -P ssh_chroot_rw_homedirs=off
# Compilers were chmod 700 as exploit-build friction; restore stock 755.
for tool in /usr/bin/gcc /usr/bin/make /usr/bin/as /usr/bin/ld; do
    [ -e "$tool" ] && run chmod 755 "$tool"
done
# fstab: drop the proc hidepid line (live-remount best-effort) and usrquota.
if [ -f /etc/fstab ] && grep -qE '^\s*proc\s+/proc\s.*hidepid=2' /etc/fstab; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would remove the hidepid=2 fstab line + remount /proc"
    else
        sed -i -E '/^\s*proc\s+\/proc\s.*hidepid=2/d' /etc/fstab
        mount -o remount,hidepid=0 /proc >/dev/null 2>&1 || true
        step "hidepid fstab entry removed"
    fi
fi
if [ -f /etc/fstab ] && grep -qE '^[^#]*\s+/\s+xfs\s+[^\s]*usrquota' /etc/fstab; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would strip usrquota from the / fstab entry (reboot-effective)"
    else
        sed -i -E 's/(^[^#]*\s+\/\s+xfs\s+[a-z0-9_,=.-]*),usrquota/\1/; s/(^[^#]*\s+\/\s+xfs\s+)usrquota,?/\1/' /etc/fstab
        step "usrquota fstab option removed (effective after reboot)"
    fi
fi

# ---------------------------------------------------------------------------
log "10/12 Remove cron jobs, logs, tmpfiles, sockets"
# ---------------------------------------------------------------------------
run rm -f /etc/cron.d/cwhpanel-renew /etc/cron.d/cwhpanel-appcatalog /etc/cron.d/cwhpanel-hostname-tls /etc/cron.d/cwhpanel-backup /etc/cron.d/cwhpanel-ftp-quota /etc/cron.d/cwhpanel-updates /etc/cron.d/cwhpanel-bandwidth /etc/cron.d/cwhpanel-mysql-governor
# Every panel drop-in (new modules add more) and crontabs owned by users that
# no longer exist (test accounts used to linger in /var/spool/cron).
if [ "$DRY" = "1" ]; then
    step "[dry-run] would remove /etc/cron.d/cwhpanel-* and orphan crontabs"
else
    rm -f /etc/cron.d/cwhpanel-* 2>/dev/null || true
    for f in /var/spool/cron/*; do
        [ -f "$f" ] || continue
        u="$(basename "$f")"
        id "$u" >/dev/null 2>&1 || rm -f "$f"
    done
fi
# Panel-owned nginx + ModSecurity configs (they reference the hostname cert and
# the live tree; leaving them behind makes the NEXT install fail `nginx -t`).
if [ "$DRY" = "1" ]; then
    step "[dry-run] would remove /etc/nginx/conf.d panel/catch-all/hosting vhosts + /etc/modsecurity"
else
    rm -f /etc/nginx/conf.d/00-cwhpanel-*.conf /etc/nginx/conf.d/cwhpanel-*.conf \
          /etc/nginx/conf.d/panel.conf /etc/nginx/conf.d/php-fpm.conf \
          /etc/nginx/conf.d/hosting-*.conf 2>/dev/null || true
    rm -f /etc/nginx/conf.d/panel.conf.bak-* /etc/nginx/conf.d/*.conf.bak-* 2>/dev/null || true
    rm -rf /etc/modsecurity 2>/dev/null || true
fi
# cwhpanel-nginx-configs
run rm -f /var/log/cwhpanel-renew.log /var/log/cwhpanel-appcatalog.log /var/log/cwhpanel-hostname-tls.log /var/log/cwhpanel-backup.log /var/log/cwhpanel-ftp-quota.log

# Hardening artifacts (sendmail wrapper, app network namespaces, MariaDB
# socket drop-in, submission sender map). Restore the host MTA symlink so
# removed panel code is not left wrapping sendmail.
if [ -L /usr/sbin/sendmail ] && [ "$(readlink -f /usr/sbin/sendmail 2>/dev/null)" = "/usr/local/sbin/cwhpanel-sendmail" ]; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would restore /usr/sbin/sendmail -> /usr/sbin/sendmail.postfix"
    else
        ln -sf /usr/sbin/sendmail.postfix /usr/sbin/sendmail
        step "restored /usr/sbin/sendmail"
    fi
fi
run rm -f /usr/local/sbin/cwhpanel-sendmail /usr/local/sbin/cwhpanel-netns
run rm -f /etc/postfix/sender_logins_submission.pcre
run rm -f /etc/sysctl.d/99-cwhpanel-appnet.conf
run rm -rf /etc/netns/cwh-*
run rm -f /etc/systemd/system/mariadb.service.d/socket-perms.conf
if command -v nft >/dev/null 2>&1; then
    run nft delete table ip cwhpanel_appnet
fi
if [ -f /etc/postfix/master.cf ] && grep -q 'sender_logins_submission' /etc/postfix/master.cf; then
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would strip the submission sender map from master.cf"
    else
        sed -i '/sender_logins_submission/d' /etc/postfix/master.cf
        systemctl reload postfix >/dev/null 2>&1 || true
        step "submission sender map removed from master.cf"
    fi
fi
run rm -rf /run/cwhpanel
if [ "$PURGE_BACKUPS" = "1" ]; then
    run rm -f /var/backups/*.tar.gz /var/backups/*.sql
    step "local backup artifacts purged (--purge-backups)"
else
    step "/var/backups left intact (pass --purge-backups to delete)"
fi

# ---------------------------------------------------------------------------
log "11/12 Remove panel units, binary, trees, web add-ons"
# ---------------------------------------------------------------------------
run rm -f /etc/systemd/system/cwhpanel-agent.service
run rm -rf /etc/systemd/system/cwhpanel-agent.service.d
run rm -f /etc/systemd/system/cwhpanel-worker.service
run rm -f /etc/systemd/system/cwhpanel-boot-restore.service
run rm -f /etc/systemd/system/cwhpanel-mail-policy.service
run systemctl daemon-reload
run systemctl reset-failed
run rm -f /usr/local/sbin/cwhpanel-agent
run rm -rf "$LIVE_DIR"
run rm -rf /var/lib/cwhpanel /var/lib/appinstaller
run rm -rf /etc/cwhpanel
# phpMyAdmin + Roundcube (tarball installs, no dnf packages).
run rm -rf /usr/share/phpMyAdmin /var/lib/phpmyadmin
run rm -rf /usr/share/roundcubemail /var/lib/roundcube
# Panel-installed Node runtimes (admin's own /usr/local/bin/node is untouched).
# Panel-installed Node runtimes live only under /opt/node-* (the agent's own
# paths). /usr/local/bin/node is never touched: on other servers it may be
# the admin's own install.
run rm -rf /opt/node-20 /opt/node-22
step "panel files removed"

# ---------------------------------------------------------------------------
log "12/12 Erase hosting-stack DNF packages"
# ---------------------------------------------------------------------------
if [ "$KEEP_PKGS" = "1" ]; then
    step "skipped (--keep-packages)"
else
    PKGS="nginx nginx-mod-modsecurity mod_security_crs
        php php-fpm php-cli php-mysqlnd php-gd php-intl php-mbstring php-xml php-pecl-zip php-process php-opcache php-imap
        mariadb-server mariadb postfix dovecot dovecot-mysql dovecot-pigeonhole
        spamassassin spamass-milter pdns pdns-backend-mysql
        clamav clamav-freshclam clamd vsftpd nodejs
        opendkim opendkim-tools
        policycoreutils-devel setools-console"
    # Remi version daemons + extension packages, only if present.
    # shellcheck disable=SC2086
    if [ "$DRY" = "1" ]; then
        step "[dry-run] would dnf -y remove: $PKGS (+ installed php*-php-* Remi packages)"
    else
        REMI="$(rpm -qa 2>/dev/null | grep -E '^php[0-9]+-php-' || true)"
        # shellcheck disable=SC2086
        dnf -y remove $PKGS $REMI >/dev/null 2>&1 || warn "dnf remove exited non-zero (see output above); continuing"
        step "hosting-stack packages erased"
        # The server packages are gone: drop the (already emptied) MariaDB
        # data dir too. Kept when databases were kept.
        if [ "$KEEP_DB" = "0" ]; then
            run rm -rf /var/lib/mysql
            step "mariadb data dir removed"
        fi
    fi
fi

# ---------------------------------------------------------------------------
log "Clean up panel-owned system integration state"
# The panel writes drop-ins into SHARED system locations. Removing its tree and
# packages leaves those behind, and stale ones actively break the next install:
# a Wants=user@<uid>.service for a deleted account fails at EVERY boot, an
# nginx unit drop-in applies to a future nginx, MariaDB tuning changes future
# server behaviour. Remove exactly the files the panel owns.
if [ "$DRY" = "1" ]; then
    step "[dry-run] would remove panel systemd drop-ins + integration files"
else
    SYSUNIT=/etc/systemd/system
    rm -f "$SYSUNIT/multi-user.target.d/cwhpanel-userenv.conf" 2>/dev/null || true
    rmdir "$SYSUNIT/multi-user.target.d" 2>/dev/null || true
    for d in "$SYSUNIT"/user@*.service.d; do
        [ -d "$d" ] || continue
        rm -f "$d/cwhpanel.conf" 2>/dev/null || true
        rmdir "$d" 2>/dev/null || true
    done
    rm -f "$SYSUNIT/nginx.service.d/cwhpanel-nofile.conf" 2>/dev/null || true
    rmdir "$SYSUNIT/nginx.service.d" 2>/dev/null || true
    rm -f "$SYSUNIT/crond.service.d/cwhpanel-watchdog.conf" 2>/dev/null || true
    rmdir "$SYSUNIT/crond.service.d" 2>/dev/null || true

    rm -f /etc/my.cnf.d/99-cwhpanel-tuning.cnf /etc/my.cnf.d/cwhpanel-bind.cnf 2>/dev/null || true
    rm -f /etc/logrotate.d/cwhpanel-modsec 2>/dev/null || true
    rm -f /etc/profile.d/cwhpanel-tmp.sh 2>/dev/null || true
    rm -f /etc/sysctl.d/99-cwhpanel.conf /etc/sysctl.d/99-cwhpanel-appnet.conf 2>/dev/null || true
    rm -f /etc/fail2ban/jail.d/cwhpanel.conf /etc/fail2ban/jail.d/cwhpanel-scanner.conf 2>/dev/null || true
    rm -f /etc/fail2ban/filter.d/cwhpanel-nginx-scanner.conf 2>/dev/null || true
    rm -f /etc/ssh/sshd_config.d/00-cwhpanel-macs.conf 2>/dev/null || true
    rm -f /etc/opendkim/hosting-key.table /etc/opendkim/hosting-signing.table \
          /etc/opendkim/hosting-internal-hosts 2>/dev/null || true
    if [ -f /etc/opendkim.conf ] && head -n 4 /etc/opendkim.conf 2>/dev/null | grep -qi cwhpanel; then
        rm -f /etc/opendkim.conf 2>/dev/null || true
    fi
    rm -f /etc/pdns/cwhpanel-tuning.conf /etc/pdns/pdns.conf /etc/pdns/pdns.conf.rpmsave 2>/dev/null || true
    rm -f /etc/php.d/99-cwhpanel-opcache.ini 2>/dev/null || true
    rm -rf /etc/netns/cwh-* 2>/dev/null || true
    # Live app network namespaces (mount units under /run/netns). They vanish on
    # reboot, but a clean uninstall must not leave mounted namespaces behind.
    for ns in /run/netns/cwh-*; do
        [ -e "$ns" ] || continue
        umount -l "$ns" 2>/dev/null || true
        rm -f "$ns" 2>/dev/null || true
    done
    rmdir /run/netns 2>/dev/null || true

    # Orphan FPM pools: a pool file whose `user =` no longer exists stops the
    # whole daemon for that PHP version ("cannot get uid for user"), so a stale
    # one from any past install must go. www.conf and pools of live users stay.
    for d in /etc/php-fpm.d /etc/opt/remi/php*/php-fpm.d; do
        [ -d "$d" ] || continue
        for pc in "$d"/*.conf; do
            [ -f "$pc" ] || continue
            case "$(basename "$pc")" in www.conf|zz-*.conf) continue ;; esac
            pu="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' "$pc" 2>/dev/null)"
            [ -n "$pu" ] || continue
            id "$pu" >/dev/null 2>&1 || rm -f "$pc" 2>/dev/null || true
        done
    done

    # Orphan account artifacts: shadow-utils cleans these on a normal
    # `userdel -r`, but an aborted suite or an older build can leave them
    # behind (a /etc/subuid range blocks nothing, a mail spool confuses Dovecot).
    for f in /etc/subuid /etc/subgid; do
        [ -f "$f" ] || continue
        cp -a "$f" "$f.cwhbak" 2>/dev/null || continue
        while IFS=: read -r name _rest; do
            [ -n "$name" ] || continue
            id "$name" >/dev/null 2>&1 || sed -i "/^${name}:/d" "$f"
        done < "$f.cwhbak"
        rm -f "$f.cwhbak" 2>/dev/null || true
    done
    for m in /var/spool/mail/*; do
        [ -f "$m" ] || continue
        u="$(basename "$m")"
        id "$u" >/dev/null 2>&1 || rm -f "$m" 2>/dev/null || true
    done
    for l in /var/lib/systemd/linger/*; do
        [ -e "$l" ] || continue
        u="$(basename "$l")"
        id "$u" >/dev/null 2>&1 || rm -f "$l" 2>/dev/null || true
    done

    systemctl daemon-reload 2>/dev/null || true
    systemctl reset-failed 2>/dev/null || true
    step "panel system integration state cleaned"
fi

# ---------------------------------------------------------------------------
log "Verify: no panel-managed state left behind"
# A half-removed account or a stray service makes the NEXT install inherit
# someone else's state, and every regression suite then fails for the wrong
# reason. This gate is the difference between "uninstall said ok" and "uninstall
# is actually clean".
LEFTOVERS=""
if [ "$KEEP_ACCOUNTS" != "1" ] && [ "$DRY" != "1" ]; then
    while read -r u _uid home; do
        case "$home" in /home/*) ;; *) continue ;; esac
        if [ -d "$home/.php" ] || [ -d "$home/nginx" ] || [ -d "$home/public_html" ] \
            || compgen -G "$home/*/public_html" >/dev/null 2>&1 \
            || { [ "$home" != "/home/$u" ]; }; then
            LEFTOVERS="$LEFTOVERS $u"
        fi
    done < <(getent passwd | awk -F: '$3>=1000 && $3<65000 {print $1, $3, $6}')
    # Jailed accounts show up in the group even when the home is already gone.
    if getent group sshjailed >/dev/null 2>&1; then
        for u in $(getent group sshjailed | cut -d: -f4 | tr ',' ' '); do
            [ -n "$u" ] && LEFTOVERS="$LEFTOVERS $u"
        done
    fi
fi
LEFTOVERS="$(echo "$LEFTOVERS" | tr ' ' '\n' | sed '/^$/d' | sort -u | tr '\n' ' ')"
if [ -n "$LEFTOVERS" ]; then
    warn "panel accounts still present:$LEFTOVERS"
    warn "fix before reinstalling: userdel -r <user> (see messages above)"
fi
UNITS_LEFT="$(systemctl list-units --all --no-legend 'cwhpanel-*' 'cwh-*' 2>/dev/null | wc -l)"
[ "$UNITS_LEFT" = "0" ] || warn "$UNITS_LEFT panel units still loaded"
CRON_LEFT="$(ls /etc/cron.d 2>/dev/null | grep -c cwh || true)"
[ "$CRON_LEFT" = "0" ] || warn "$CRON_LEFT panel cron drop-ins still present"

if [ -n "$LEFTOVERS" ] || [ "$UNITS_LEFT" != "0" ] || [ "$CRON_LEFT" != "0" ]; then
    step "verification FAILED — host is not clean (see warnings above)"
    DIRTY=1
else
    step "verification passed: no panel accounts, units or cron drop-ins remain"
    DIRTY=0
fi

# ---------------------------------------------------------------------------
cat <<EOF

Done. cwhpanel has been uninstalled.


  Config snapshot : $BACKUP_DIR (restore manually if needed)
  Panel DB/users  : $([ "$KEEP_DB" = "1" ] && echo "KEPT (--keep-databases)" || echo "dropped")
  Accounts        : $([ "$KEEP_ACCOUNTS" = "1" ] && echo "KEPT (--keep-accounts)" || echo "deleted")
  DNF packages    : $([ "$KEEP_PKGS" = "1" ] && echo "KEPT (--keep-packages)" || echo "erased")
  /var/backups    : $([ "$PURGE_BACKUPS" = "1" ] && echo "purged" || echo "kept (delete manually if unwanted)")

Left in place on purpose (not panel-owned):
  - epel-release, git, curl, openssl, jq, python3, policycoreutils SELinux tools
  - /usr/local/bin/node (may be the admin's own; /opt/node-20/22 were removed)
  - firewalld reject/block rich rules (stale attacker blocks stay safe)
  - SELinux booleans httpd_can_network_connect_db / httpd_enable_homedirs /
    httpd_read_user_content / antivirus_can_scan_system (generic, may serve others)
  - opencode-web.service (separate tool, port 4096 — never touched)
  - this repo ($HERE) — delete it manually: rm -rf $HERE

Recommended: reboot (applies the fstab usrquota/hidepid changes, clears any
leftover mounts/slices), then verify with:
  systemctl status cwhpanel-agent cwhpanel-worker cwhpanel-mail-policy
  ls /opt/cwhpanel /etc/cwhpanel /run/cwhpanel
  mysql -e 'SHOW DATABASES;'
EOF
[ "${DIRTY:-0}" = "0" ] || exit 1
