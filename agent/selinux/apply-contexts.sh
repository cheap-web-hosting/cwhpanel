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
AGENT_EXEC_PATH='/usr/local/sbin/cwhpanel-agent'
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
  # File contexts defined by the policy modules only become deletable once the
  # modules are gone, so this runs twice: before (removes genuinely local
  # overrides) and after the module removal (removes the module-defined ones).
  delete_fcontexts() {
    for p in "${PATHS[@]}"; do
      semanage fcontext -d "$p" >/dev/null 2>&1 || true
    done
    for p in "${JAIL_PATHS[@]}"; do
      semanage fcontext -d "$p" >/dev/null 2>&1 || true
    done
    semanage fcontext -d "$SOCK_PATH" >/dev/null 2>&1 || true
    semanage fcontext -d "$AGENT_EXEC_PATH" >/dev/null 2>&1 || true
  }
  echo "==> Removing local fcontext rules..."
  delete_fcontexts
  echo "==> Removing worker port label..."
  semanage port -d -t "$PORT_TYPE" -p tcp "$PORT_RANGE" 2>/dev/null || true
  echo "==> Removing app port label..."
  semanage port -d -t "$APP_PORT_TYPE" -p tcp "$APP_PORT_RANGE" 2>/dev/null || true
  echo "==> Removing policy modules..."
  # Module removal is order-sensitive: the base module references types owned by
  # the agent modules, so removing it while they are loaded fails with
  # "Failed to resolve typeattributeset". Remove dependants first, always at the
  # module's explicit priority (a bare `semodule -r` can print
  # "Removing last <module>" and still fail the store rebuild), and retry across
  # passes so a module left behind by a failed pass is picked up next time.
  module_prio() {
    semodule --list-modules=full 2>/dev/null | awk -v m="$1" '$2 == m {print $1; exit}'
  }
  remove_module() {
    local m="$1" prio
    semodule -l 2>/dev/null | grep -qx "$m" || return 0
    prio="$(module_prio "$m")"
    if [ -n "$prio" ]; then
      semodule -X "$prio" -r "$m" 2>&1 | sed 's/^/    /' || true
    else
      semodule -r "$m" 2>&1 | sed 's/^/    /' || true
    fi
    semodule -l 2>/dev/null | grep -qx "$m" && return 1
    return 0
  }
  for _pass in 1 2 3; do
    _left=0
    for m in "${MODULES_DEP[@]}"; do
      remove_module "$m" || _left=$((_left + 1))
    done
    [ "$_left" = "0" ] && break
    sleep 1
  done
  # Deterministic fallback: libsemanage can get stuck in a state where every
  # removal fails ("type <x> is not defined" / "Failed to resolve
  # typeattributeset") even though the module is still installed. Drop the
  # module's store directory at every priority and rebuild the policy — scoped
  # to this panel's own module names.
  _stuck=0
  for m in "${MODULES_DEP[@]}"; do
    semodule -l 2>/dev/null | grep -qx "$m" && _stuck=1
  done
  if [ "$_stuck" = "1" ]; then
    echo "==> Policy store rebuild (stuck modules)..."
    for m in "${MODULES_DEP[@]}"; do
      rm -rf /var/lib/selinux/targeted/active/modules/*/"$m" 2>/dev/null || true
    done
    semodule -B >/dev/null 2>&1 || true
  fi
  for m in "${MODULES_DEP[@]}"; do
    if semodule -l 2>/dev/null | grep -qx "$m"; then
      echo "could not remove policy module: $m" >&2
    fi
  done
  echo "==> Removing module-defined fcontext rules..."
  delete_fcontexts

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
