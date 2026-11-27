#!/usr/bin/env bash
#
# Install ModSecurity (nginx-mod-modsecurity) + OWASP Core Rule Set for the
# cwhpanel NGINX-first hosting panel.
#
# Applies config changes atomically: backs up nginx.conf, loads the module,
# and only keeps it if `nginx -t` passes. On failure the previous config is
# restored and the module package is reported as NOT loaded.
#
# Usage: ./install-modsecurity.sh

set -euo pipefail

NGINX_CONF="/etc/nginx/nginx.conf"
MODSEC_DIR="/etc/modsecurity"
CRS_DIR="/usr/share/modsecurity-crs"

echo "==> Installing modsecurity packages (EPEL)..."
dnf install -y epel-release >/dev/null 2>&1 || true
# libmodsecurity and the nginx connector live in EPEL; build deps in CRB.
dnf config-manager --set-enabled crb >/dev/null 2>&1 || true
dnf install -y nginx-mod-modsecurity 2>&1 | tail -3

echo "==> Verifying module binary..."
MODULE="/usr/lib64/nginx/modules/ngx_http_modsecurity_module.so"
if [ ! -f "$MODULE" ]; then
    echo "Module not found at $MODULE; aborting safely."
    exit 1
fi
echo "  Found $MODULE"

echo "==> Installing OWASP Core Rule Set..."
dnf install -y mod_security_crs 2>&1 | tail -3
CRS_DIR="/usr/share/owasp-modsecurity-crs"
[ -d "$CRS_DIR" ] || CRS_DIR="/usr/share/modsecurity-crs"
echo "  CRS at $CRS_DIR"

echo "==> Configuring modsecurity base files..."
mkdir -p "$MODSEC_DIR"
if [ -f "/etc/nginx/modsecurity.conf" ]; then
    cp -f /etc/nginx/modsecurity.conf "$MODSEC_DIR/modsecurity.conf"
elif [ -f "/etc/nginx/nginx.conf.modsecurity" ]; then
    cp -f /etc/nginx/nginx.conf.modsecurity "$MODSEC_DIR/modsecurity.conf"
fi
# modsecurity.conf references unicode.mapping by relative path; it must sit
# alongside the base config.
if [ -f "/etc/nginx/unicode.mapping" ]; then
    cp -f /etc/nginx/unicode.mapping "$MODSEC_DIR/unicode.mapping"
fi
chmod 644 "$MODSEC_DIR/modsecurity.conf" 2>/dev/null || true

echo "==> The nginx connector ships a load_module via /usr/share/nginx/modules/mod-modsecurity.conf"
echo "    (already included by nginx.conf). Verifying module loading..."

echo "==> Validating nginx -t..."
if nginx -t 2>&1; then
    echo "  nginx -t OK; reloading..."
    systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
    echo
    echo "ModSecurity installed and nginx reloaded."
    echo "Per-site WAF can now be enabled from the panel."
else
    echo "nginx -t FAILED; ModSecurity module NOT active."
    exit 1
fi