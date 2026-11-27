#!/bin/bash
# cwhpanel-cron-run — panel cron job wrapper.
#
# crontab lines are `<schedule> /usr/local/bin/cwhpanel-cron-run <job-id>
# <command>` (command single-quoted by the panel at sync time). The command
# runs via `sh -c`, i.e. with IDENTICAL semantics to a bare crontab line
# (pipes, redirects and env all behave as before).
#
# The command is routed into the account's cgroup slice via its systemd user
# manager (`systemd-run --user --scope`) — the panel provisions every account
# with linger + a `user@<uid>.service` drop-in so the manager is up and the
# slice exists, which means cron jobs now inherit the account's CPU/memory/
# process/IO caps (previously they ran uncapped under crond). If the user
# manager is unavailable for any reason we fall back to direct execution, so
# a scheduling hiccup never drops a job.
#
# The wrapper captures exit code, output (tail-capped) and duration, echoes
# the capped output (so cron mail behavior is unchanged), records everything
# as JSON in ~/.cwhpanel-cron/<job-id>.json for the panel to display, and
# always exits 0 (the truth lives in the JSON, never in the exit code —
# a failing command must not fail the reporting path).
set -u
JOB_ID="${1:?usage: cwhpanel-cron-run <job-id> <command>}"
CMD="${2:?usage: cwhpanel-cron-run <job-id> <command>}"
STATE_DIR="${HOME:-/tmp}/.cwhpanel-cron"
CAP_BYTES=8192

mkdir -p "$STATE_DIR" 2>/dev/null || true

START=$(date +%s)
OUT=""
CODE=0
SLICED=0
if [ -z "${CWH_CRON_SLICED:-}" ] && command -v systemd-run >/dev/null 2>&1; then
  # Always use the ACCOUNT's own runtime dir (from the effective UID) — an
  # inherited XDG_RUNTIME_DIR would point at the calling session (e.g. root)
  # and put the job in the wrong user manager.
  XDG_RUNTIME_DIR="/run/user/$(id -u)"
  if [ -S "$XDG_RUNTIME_DIR/systemd/private" ]; then
    OUT=$(CWH_CRON_SLICED=1 XDG_RUNTIME_DIR="$XDG_RUNTIME_DIR" \
      systemd-run --user --quiet --scope -- env CWH_CRON_SLICED=1 \
      /bin/sh -c "$CMD" 2>&1)
    SD_RC=$?
    # Distinguish a failed scope START (user manager down/empty) from the
    # inner command's own exit code: only a start failure falls back to
    # direct execution.
    if [ "$SD_RC" -ne 0 ] && printf '%s' "$OUT" | grep -q 'Failed to start transient'; then
      SLICED=0
    else
      SLICED=1
      CODE=$SD_RC
    fi
  fi
fi
if [ "$SLICED" -ne 1 ]; then
  OUT=$(/bin/sh -c "$CMD" 2>&1)
  CODE=$?
fi
END=$(date +%s)

OUT=$(printf '%s' "$OUT" | tail -c "$CAP_BYTES")
printf '%s\n' "$OUT"

# Program via -c, output via argv (bash variables cannot hold NUL bytes
# anyway — command substitution strips them — so argv transport is exact).
# `sliced` records whether the job actually ran inside the account cgroup
# slice (1) or fell back to direct execution (0) — a routing failure is
# observable in the state JSON, never silent.
/usr/bin/python3 -c '
import json, os, sys
path, code, dur_ms, ran_at, data, sliced = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4]), sys.argv[5], int(sys.argv[6])
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump({"exit": code, "duration_ms": dur_ms, "ran_at": ran_at, "output": data, "sliced": sliced}, f)
os.replace(tmp, path)
' "$STATE_DIR/$JOB_ID.json" "$CODE" "$(( (END - START) * 1000 ))" "$END" "$OUT" "$SLICED" 2>/dev/null || true
exit 0
