#!/usr/bin/env bash
# Runtime policy install: load the compiled modules, then apply the local
# fcontexts, worker/app port labels and booleans (apply-contexts.sh).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for pp in "$here"/*.pp; do
    [ -f "$pp" ] && semodule -i "$pp"
done
bash "$here/apply-contexts.sh"
echo "SELinux policy modules loaded."
