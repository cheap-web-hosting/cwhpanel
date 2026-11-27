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

# MUST mirror agent/install.sh. A packaged install used to write only the
# uid/gid lists (and the wrong group variable), so the socket stayed
# root:root (the panel could not connect), the 30s default killed long ops
# (panel updates, app CLIs) and the trust-model keys/mode were lost.
{
    echo "[Service]"
    echo "Environment=CWH_AGENT_SOCKET=$SOCKET"
    echo "Environment=CWH_AGENT_ALLOWED_UIDS=$MERGE_UIDS"
    echo "Environment=CWH_AGENT_ALLOWED_GIDS=$MERGE_GIDS"
    echo "Environment=CWH_AGENT_TIMEOUT=300"
    if [ -n "$PANEL_GROUP_NAME" ]; then
        echo "Environment=CWH_AGENT_SOCKET_GROUP=$PANEL_GROUP_NAME"
    fi
    PANEL_ENV_SRC="$here/../.env"
    if [ -f /opt/cwhpanel/.env ]; then
        PANEL_ENV_SRC=/opt/cwhpanel/.env
    fi
    ADMIN_KEY="$(grep -E '^CWH_PANEL_ADMIN_KEY=' "$PANEL_ENV_SRC" 2>/dev/null | head -n1 | cut -d= -f2- || true)"
    CUSTOMER_KEY="$(grep -E '^CWH_PANEL_CUSTOMER_KEY=' "$PANEL_ENV_SRC" 2>/dev/null | head -n1 | cut -d= -f2- || true)"
    [ -n "$ADMIN_KEY" ] && echo "Environment=CWH_AGENT_ADMIN_KEY=$ADMIN_KEY"
    [ -n "$CUSTOMER_KEY" ] && echo "Environment=CWH_AGENT_CUSTOMER_KEY=$CUSTOMER_KEY"
    if [ -z "${CWH_AGENT_TICKET_MODE:-}" ] && [ -f "$OLD_OVERRIDE" ]; then
        CWH_AGENT_TICKET_MODE="$(grep -E '^Environment=CWH_AGENT_TICKET_MODE=' "$OLD_OVERRIDE" 2>/dev/null | head -n1 | cut -d= -f2- || true)"
    fi
    if [ -z "${CWH_AGENT_TICKET_MODE:-}" ]; then
        echo "Environment=CWH_AGENT_TICKET_MODE=enforce"
    else
        echo "Environment=CWH_AGENT_TICKET_MODE=$CWH_AGENT_TICKET_MODE"
    fi
    if [ -f "$OLD_OVERRIDE" ]; then
        grep -E '^Environment=CWH_AGENT_(ADMIN|CUSTOMER)_KEY_OLD=' "$OLD_OVERRIDE" 2>/dev/null || true
    fi
} > /etc/systemd/system/cwhpanel-agent.service.d/override.conf
chmod 750 /etc/systemd/system/cwhpanel-agent.service.d 2>/dev/null || true
chmod 640 /etc/systemd/system/cwhpanel-agent.service.d/override.conf 2>/dev/null || true

systemctl daemon-reload
systemctl enable cwhpanel-agent >/dev/null 2>&1 || true
systemctl restart cwhpanel-agent
systemctl is-active cwhpanel-agent

echo "Agent installed. Socket: $SOCKET (peers: $MERGE_UIDS / $MERGE_GIDS)"
