#!/usr/bin/env bash
#
# Apply the runtime SELinux state for cwhpanel: local file contexts, worker/app
# port labels and policy booleans (or remove them with --unload).
#
# Shared by:
#   - setup.sh            (source installs: build + load + call this)
#   - install-policy.sh   (runtime bundles: load compiled .pp + call this)
#   - uninstall.sh        (--unload)
#
# Keep this file free of policy SOURCE; it is shipped in runtime bundles.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
name="cwhpanel"

PATHS=(
  '/home/[^/]+/public_html(/.*)?'
  '/home/[^/]+/[^/]+/public_html(/.*)?'
  '/home/[^/]+/www(/.*)?'
  '/home/[^/]+/logs(/.*)?'
  '/home/[^/]+/tmp(/.*)?'
  '/home/[^/]+/nginx(/.*)?'
  '/home/[^/]+/.php(/.*)?'
)

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

SOCK_PATH='/var/run/cwhpanel(/.*)?'
PORT_TYPE='cwhpanel_worker_port_t'
PORT_RANGE='12000-12999'
APP_PORT_TYPE='cwhpanel_app_port_t'
APP_PORT_RANGE='22000-22999'
# Unload dependants first: removing the base module while the agent
# allow-module is loaded fails ("module in use").
MODULES_DEP=(cwhpanel_agent_allow cwhpanel_agent cwhpanel)

apply() {
  echo "==> Confining the privileged agent..."
  if semanage fcontext -l 2>/dev/null | grep -qF '/usr/local/sbin/cwhpanel-agent'; then
    semanage fcontext -m -t "${name}_agent_exec_t" '/usr/local/sbin/cwhpanel-agent' 2>/dev/null || true
  else
    semanage fcontext -a -t "${name}_agent_exec_t" '/usr/local/sbin/cwhpanel-agent' 2>/dev/null || true
  fi
  restorecon -v /usr/local/sbin/cwhpanel-agent 2>/dev/null || true

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

  echo "==> Enabling policy booleans..."
  setsebool -P httpd_can_network_connect_db=on 2>/dev/null || true
  setsebool -P httpd_can_network_connect=on 2>/dev/null || true
  setsebool -P httpd_enable_homedirs=on 2>/dev/null || true
  setsebool -P httpd_read_user_content=on 2>/dev/null || true
  setsebool -P ssh_chroot_rw_homedirs=on 2>/dev/null || true
  echo "==> Runtime SELinux state applied."
}

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
  # semodule can report success ("Removing last <mod> module") while the module
  # is still installed afterwards — observed on a fresh host where only the
  # first of three removals took effect. Remove each module until semodule -l
  # no longer lists it, retrying at its explicit priority.
  remove_module() {
    local m="$1" i prio
    for i in 1 2 3 4; do
      semodule -l 2>/dev/null | grep -qx "$m" || return 0
      semodule -r "$m" >/dev/null 2>&1 || true
      semodule -l 2>/dev/null | grep -qx "$m" || return 0
      prio="$(semodule --list-modules=full 2>/dev/null | awk -v m="$m" '$2 == m {print $1; exit}')"
      if [ -n "$prio" ]; then
        semodule -X "$prio" -r "$m" >/dev/null 2>&1 || true
      fi
      sleep 1
    done
    semodule -l 2>/dev/null | grep -qx "$m" && return 1
    return 0
  }
  for m in "${MODULES_DEP[@]}"; do
    remove_module "$m" || echo "could not remove policy module: $m" >&2
  done
  semanage fcontext -d '/usr/local/sbin/cwhpanel-agent' 2>/dev/null || true

  local remnants=0
  for m in "${MODULES_DEP[@]}"; do
    semodule -l 2>/dev/null | grep -qx "$m" && { echo "leftover policy module: $m"; remnants=1; } || true
  done
  semanage fcontext -l 2>/dev/null | grep -F "${name}_" && remnants=1 || true
  semanage port -l 2>/dev/null | grep -F "${name}_" && remnants=1 || true
  [ "$remnants" = "0" ] || { echo "unload incomplete (see leftovers above)" >&2; return 1; }
  echo "==> Unload verified clean."
}

case "${1:-}" in
  --unload) unload ;;
  *) apply ;;
esac
