#!/usr/bin/env bash
#
# Build (and optionally load) the cwhpanel SELinux policy modules.
#
#   ./build.sh            build only (produces cwhpanel.pp, cwhpanel_agent.pp,
#                         cwhpanel_agent_allow.pp)
#   ./build.sh --load     build and install all modules via semodule
#   ./build.sh --unload   remove the modules
#
# After loading, run `restorecon -R /home/<account>` (the agent does this during
# provisioning) to relabel existing account content, and
# `restorecon /usr/local/sbin/cwhpanel-agent` to put the agent into its confined
# domain (cwhpanel_agent_t).

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$here"

action="${1:-}"
MODULES="cwhpanel cwhpanel_agent cwhpanel_agent_allow"

case "$action" in
  --unload)
    echo "==> Removing modules..."
    for m in $MODULES; do semodule -r "$m" || true; done
    exit 0
    ;;
esac

echo "==> Building policy package..."
make -f Makefile clean >/dev/null 2>&1 || true
make -f Makefile

case "$action" in
  --load)
    echo "==> Installing modules..."
    for m in $MODULES; do semodule -i "$m.pp"; done
    echo "==> Loaded. Relabel account content with: restorecon -R /home/<account>"
    echo "==> Confine the agent with: restorecon /usr/local/sbin/cwhpanel-agent && systemctl restart cwhpanel-agent"
    ;;
  *)
    echo "==> Built ${MODULES// /, }.pp (use --load to install, --unload to remove)"
    ;;
esac