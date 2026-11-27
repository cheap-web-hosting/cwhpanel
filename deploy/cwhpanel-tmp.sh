# Give hosting-account shells a private per-user temporary directory instead of
# sharing the world-writable host /tmp (I-8). Panel/app processes already use
# PrivateTmp; interactive/SSH shells and cron read this.
[ -d "$HOME" ] || return 0
mkdir -p "$HOME/tmp" 2>/dev/null && chmod 700 "$HOME/tmp" 2>/dev/null
TMPDIR="$HOME/tmp"; TMP="$TMPDIR"; TEMP="$TMPDIR"; export TMPDIR TMP TEMP
