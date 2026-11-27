#!/usr/bin/env bash
#
# Install the cwhpanel privileged agent from a PACKAGED release bundle
# (no cargo/Rust source required — used inside the runtime artifact).
#
# Usage: ./install-packaged.sh [panel-uid] [panel-gid] [panel-group-name]
#
# Mirrors agent/install.sh behavior but installs the prebuilt binary that
# ships next to this script (agent/cwhpanel-agent).

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_UID="${1:-0}"
PANEL_GID="${2:-0}"
PANEL_GROUP_NAME="${3:-}"
SOCKET="/run/cwhpanel/agent.sock"

if [ ! -x "$here/cwhpanel-agent" ]; then
    echo "ERROR: packaged agent binary missing (agent/cwhpanel-agent)" >&2
    exit 1
fi

echo "==> Installing binary to /usr/local/sbin/cwhpanel-agent..."
install -m 0755 "$here/cwhpanel-agent" /usr/local/sbin/cwhpanel-agent
[ -f "$here/cwhpanel-cron-jail" ] && install -m 0755 "$here/cwhpanel-cron-jail" /usr/local/bin/cwhpanel-cron-jail || true

echo "==> Installing unattended-install helpers..."
install -d -m 0755 /usr/local/share/cwhpanel
for f in wp-install.php dw-install.php mt-install.php drupal-install.php piwigo-install.php; do
    [ -f "$here/$f" ] && install -m 0644 "$here/$f" "/usr/local/share/cwhpanel/$f" || true
done

echo "==> Installing systemd unit..."
install -m 0644 "$here/cwhpanel-agent.service" /etc/systemd/system/cwhpanel-agent.service

mkdir -p /etc/systemd/system/cwhpanel-agent.service.d
OLD_OVERRIDE=/etc/systemd/system/cwhpanel-agent.service.d/override.conf
MERGE_UIDS="0,$PANEL_UID"
MERGE_GIDS="0,$PANEL_GID"
if [ -f "$OLD_OVERRIDE" ]; then
    OLD_UIDS="$(grep -E '^Environment=CWH_AGENT_ALLOWED_UIDS=' "$OLD_OVERRIDE" 2>/dev/null | head -n1 | sed 's/^Environment=[^=]*=//' || true)"
    OLD_GIDS="$(grep -E '^Environment=CWH_AGENT_ALLOWED_GIDS=' "$OLD_OVERRIDE" 2>/dev/null | head -n1 | sed 's/^Environment=[^=]*=//' || true)"
    if [ -n "$OLD_UIDS" ]; then
        for u in ${OLD_UIDS//,/ }; do
            case ",$MERGE_UIDS," in *,"$u",*) ;; *) MERGE_UIDS="$MERGE_UIDS,$u" ;; esac
        done
    fi
    if [ -n "$OLD_GIDS" ]; then
        for g in ${OLD_GIDS//,/ }; do
            case ",$MERGE_GIDS," in *,"$g",*) ;; *) MERGE_GIDS="$MERGE_GIDS,$g" ;; esac
        done
    fi
fi
if [ -z "$PANEL_GROUP_NAME" ] && [ "$PANEL_GID" != "0" ]; then
    PANEL_GROUP_NAME="$(getent group "$PANEL_GID" | cut -d: -f1 || true)"
fi

cat > /etc/systemd/system/cwhpanel-agent.service.d/override.conf <<EOF
[Service]
Environment=CWH_AGENT_ALLOWED_UIDS=$MERGE_UIDS
Environment=CWH_AGENT_ALLOWED_GIDS=$MERGE_GIDS
Environment=CWH_AGENT_ALLOWED_GROUPS=${PANEL_GROUP_NAME:-}
EOF

systemctl daemon-reload
systemctl enable cwhpanel-agent >/dev/null 2>&1 || true
systemctl restart cwhpanel-agent
systemctl is-active cwhpanel-agent

echo "Agent installed. Socket: $SOCKET (peers: $MERGE_UIDS / $MERGE_GIDS)"
