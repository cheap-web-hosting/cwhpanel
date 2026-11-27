#!/usr/bin/env bash
set -euo pipefail
for p in /usr/share/selinux/targeted/cwhpanel*.pp; do :; done
for pp in "$(dirname "$0")"/*.pp; do
    [ -f "$pp" ] && semodule -i "$pp"
done
echo "SELinux policy modules loaded."
