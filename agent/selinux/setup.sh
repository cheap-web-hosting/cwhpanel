#!/usr/bin/env bash
#
# Set up the cwhpanel SELinux policy: build + load the module, add local file
# context overrides (which take precedence over the base policy), and relabel
# account content.
#
#   ./setup.sh              build, load, add local fcontext rules
#   ./setup.sh --unload     remove the module and local fcontext rules
#
# Account content paths (/home/<user>/public_html, /www, /logs, /tmp) are
# labeled cwhpanel_account_t so nginx/PHP-FPM (httpd_t) can serve them via the
# explicit allow rules in cwhpanel.te, without enabling httpd_enable_homedirs.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
name="cwhpanel"

PATHS=(
  '/home/[^/]+/public_html(/.*)?'
  # Addon-domain docroots (/home/<user>/<domain>/public_html). Without this
  # spec restorecon falls back to user_home_t and the confined agent's relabel
  # is denied (provisioning add-on domains with the agent fails).
  '/home/[^/]+/[^/]+/public_html(/.*)?'
  '/home/[^/]+/www(/.*)?'
  '/home/[^/]+/logs(/.*)?'
  '/home/[^/]+/tmp(/.*)?'
  '/home/[^/]+/nginx(/.*)?'
  '/home/[^/]+/.php(/.*)?'
)

# SSH chroot jail skeletons (cwhpanel_jail_t). Deliberately no specs for the
# usr/ and home/ mountpoints: bind-mounted host content keeps its own labels.
JAIL_PATHS=(
  '/jail'
  '/jail/[^/]+'
  '/jail/[^/]+/etc(/.*)?'
  '/jail/[^/]+/dev(/.*)?'
  '/jail/[^/]+/tmp(/.*)?'
  '/jail/[^/]+/bin'
  '/jail/[^/]+/lib'
  '/jail/[^/]+/lib64'
  '/jail/[^/]+/sbin'
)

# The agent control socket is labeled cwhpanel_agent_sock_t so httpd_t can
# connect to it. /run is equivalent to /var/run, so use the canonical path.
SOCK_PATH='/var/run/cwhpanel(/.*)?'

# Per-account nginx worker loopback ports (12000 + account_id). The port type is
# declared in cwhpanel.te (corenet_port); this range assignment binds the ports.
PORT_TYPE='cwhpanel_worker_port_t'
PORT_RANGE='12000-12999'

# Catalog/proxied app loopback ports (AppService::APP_PORT_MIN/MAX).
APP_PORT_TYPE='cwhpanel_app_port_t'
APP_PORT_RANGE='22000-22999'

unload() {
  echo "==> Removing local fcontext rules..."
  for p in "${PATHS[@]}"; do
    semanage fcontext -d "$p" 2>/dev/null || true
  done
  for p in "${JAIL_PATHS[@]}"; do
    semanage fcontext -d "$p" 2>/dev/null || true
  done
  semanage fcontext -d "$SOCK_PATH" 2>/dev/null || true
  echo "==> Removing worker port label..."
  semanage port -d -t "$PORT_TYPE" -p tcp "$PORT_RANGE" 2>/dev/null || true
  echo "==> Removing app port label..."
  semanage port -d -t "$APP_PORT_TYPE" -p tcp "$APP_PORT_RANGE" 2>/dev/null || true
  echo "==> Removing policy modules..."
  # All cwhpanel modules (base + confined agent domain + its allow set).
  for m in cwhpanel cwhpanel_agent cwhpanel_agent_allow; do
    semodule -r "$m" 2>/dev/null || true
  done
  # Also drop the agent's exec-type fcontext.
  semanage fcontext -d '/usr/local/sbin/cwhpanel-agent' 2>/dev/null || true
  # Verify: fail loudly listing anything left behind (uninstaller relies on this).
  local remnants=0
  for m in cwhpanel cwhpanel_agent cwhpanel_agent_allow; do
    semodule -l 2>/dev/null | grep -qx "$m" && { echo "leftover policy module: $m"; remnants=1; } || true
  done
  semanage fcontext -l 2>/dev/null | grep -F "${name}_" && remnants=1 || true
  semanage fcontext -l 2>/dev/null | grep -F "$name" && remnants=1 || true
  semanage port -l 2>/dev/null | grep -F "${name}_" && remnants=1 || true
  [ "$remnants" = "0" ] || { echo "unload incomplete (see leftovers above)" >&2; return 1; }
  echo "==> Unload verified clean."
}

case "${1:-}" in
  --unload)
    unload
    exit 0
    ;;
esac

echo "==> Building and loading policy modules (base + confined agent)..."
"$here/build.sh" --load

echo "==> Confining the privileged agent..."
# The agent binary is labeled with the confined exec type; launching it through
# systemd transitions it into cwhpanel_agent_t (see cwhpanel_agent.te).
if semanage fcontext -l 2>/dev/null | grep -qF '/usr/local/sbin/cwhpanel-agent'; then
  semanage fcontext -m -t "${name}_agent_exec_t" '/usr/local/sbin/cwhpanel-agent' 2>/dev/null || true
else
  semanage fcontext -a -t "${name}_agent_exec_t" '/usr/local/sbin/cwhpanel-agent' 2>/dev/null || true
fi
restorecon -v /usr/local/sbin/cwhpanel-agent 2>/dev/null || true
echo "    (restart cwhpanel-agent to enter cwhpanel_agent_t)"

echo "==> Assigning per-account nginx worker ports..."
if semanage port -l 2>/dev/null | grep -qF "$PORT_TYPE"; then
  semanage port -m -t "$PORT_TYPE" -p tcp "$PORT_RANGE"
else
  semanage port -a -t "$PORT_TYPE" -p tcp "$PORT_RANGE"
fi

echo "==> Assigning catalog app ports..."
if semanage port -l 2>/dev/null | grep -qF "$APP_PORT_TYPE"; then
  semanage port -m -t "$APP_PORT_TYPE" -p tcp "$APP_PORT_RANGE"
else
  semanage port -a -t "$APP_PORT_TYPE" -p tcp "$APP_PORT_RANGE"
fi

echo "==> Adding local fcontext overrides (highest precedence)..."
for p in "${PATHS[@]}"; do
  if semanage fcontext -l 2>/dev/null | grep -qF "$p"; then
    semanage fcontext -m -t "${name}_account_t" "$p"
  else
    semanage fcontext -a -t "${name}_account_t" "$p"
  fi
done

# Agent socket type (so httpd_t can connect + write).
if semanage fcontext -l 2>/dev/null | grep -qF "$SOCK_PATH"; then
  semanage fcontext -m -t "${name}_agent_sock_t" "$SOCK_PATH"
else
  semanage fcontext -a -t "${name}_agent_sock_t" "$SOCK_PATH"
fi

echo "==> Adding jail fcontext overrides..."
for p in "${JAIL_PATHS[@]}"; do
  if semanage fcontext -l 2>/dev/null | grep -qF "$p"; then
    semanage fcontext -m -t "${name}_jail_t" "$p"
  else
    semanage fcontext -a -t "${name}_jail_t" "$p"
  fi
done

echo "==> Relabeling agent socket directory..."
restorecon -R /var/run/cwhpanel 2>/dev/null || true

echo "==> Removing orphaned legacy policy (open-hosting-agent)..."
# Previous panel generation. Its types (open_hosting_agent_*, hosting_mail_*)
# linger on upgraded hosts — notably a /var/log/maillog pin that silently
# stops rsyslog. Purge only when provably unused (no processes in its
# domains, none of its paths exist); a box still running the old panel
# keeps it untouched.
if semodule -l 2>/dev/null | grep -qx 'open-hosting-agent'; then
  legacy_in_use=0
  ps -eo context 2>/dev/null | grep -q 'open_hosting_agent' && legacy_in_use=1 || true
  [ -e /usr/local/lib/open-hosting-panel ] && legacy_in_use=1 || true
  [ -e /var/run/open-hosting-panel ] && legacy_in_use=1 || true
  if [ "$legacy_in_use" = "0" ]; then
    semanage fcontext -d '/var/run/open-hosting-panel(/.*)?' 2>/dev/null || true
    semodule -r open-hosting-agent 2>/dev/null || true
    restorecon /var/log/maillog 2>/dev/null || true
    echo "    legacy open-hosting-agent policy removed."
  else
    echo "    legacy module still in use, left in place."
  fi
fi

echo "==> Enabling policy booleans..."
# Allow the confined panel/PHP-FPM (httpd_t) to reach MariaDB over localhost.
setsebool -P httpd_can_network_connect_db=on 2>/dev/null || true
# Allow PHP-FPM workers (httpd_t) to make outbound HTTPS/API calls.
# WordPress core needs this (updates, api.wordpress.org, feeds/news widgets):
# without it every wp_remote_get()/curl call fails with cURL error 7
# (AVC name_connect denied to http_port_t — bitten live on myownhost.ovh).
setsebool -P httpd_can_network_connect=on 2>/dev/null || true
# Allow per-account php-fpm to traverse/read account homes (MCS categories still
# enforce account-to-account isolation).
setsebool -P httpd_enable_homedirs=on 2>/dev/null || true
setsebool -P httpd_read_user_content=on 2>/dev/null || true
# Allow sshd to read authorized_keys inside chroot jails (/jail/<user>).
# The agent also enables the runtime variant on every jail create.
setsebool -P ssh_chroot_rw_homedirs=on 2>/dev/null || true

echo "==> Done."
echo
echo "Relabel account content with:"
echo "  restorecon -R /home/<account>"
echo
echo "To remove: ./setup.sh --unload"
