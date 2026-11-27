#!/usr/bin/env bash
# Runtime policy install: load the compiled modules, then apply the local
# fcontexts, worker/app port labels and booleans (apply-contexts.sh).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Prefer building from source on the target: .pp modules are sensitive to the
# host's exact policy build. Fall back to the prebuilt modules when the
# toolchain is unavailable.
if command -v checkmodule >/dev/null 2>&1 && [ -f "$here/build.sh" ]; then
    ( cd "$here" && ./build.sh --load )
else
    for pp in "$here"/*.pp; do
        [ -f "$pp" ] && semodule -i "$pp"
    done
fi
bash "$here/apply-contexts.sh"
echo "SELinux policy modules loaded."
