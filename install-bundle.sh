#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "==> cwhpanel runtime bundle installer"
echo "==> Installing agent (packaged binary)..."
"$here/agent/install-packaged.sh" "${1:-0}" "${2:-0}" "${3:-}"
echo "==> Loading SELinux policy (if not already)..."
if command -v semodule >/dev/null; then
    "$here/agent/selinux/install-policy.sh" || true
fi
echo
echo "Next steps:"
echo "  1. On a fresh host, provision the full hosting stack:"
echo "       ./scripts/turn-up.sh --non-interactive"
echo "  2. Initialize the panel DB:  php scripts/install.php && php scripts/migrate.php"
