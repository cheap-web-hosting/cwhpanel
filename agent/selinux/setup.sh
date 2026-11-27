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
  bash "$here/apply-contexts.sh" --unload
}

case "${1:-}" in
  --unload)
    unload
    exit 0
    ;;
esac

echo "==> Building and loading policy modules (base + confined agent)..."
"$here/build.sh" --load

echo "==> Applying runtime SELinux state (contexts, ports, booleans)..."
bash "$here/apply-contexts.sh"

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

echo "==> Done."
echo
echo "Relabel account content with:"
echo "  restorecon -R /home/<account>"
echo
echo "To remove: ./setup.sh --unload"
