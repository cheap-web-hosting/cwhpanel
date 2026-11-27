#!/bin/bash
# Add the submission-only sender-login pcre map (catch-all deny) to the
# submission/smtps services in master.cf so authenticated clients cannot spoof
# an address with no owner. Inbound :25 keeps the exact-match hash map and is
# unaffected. Idempotent; safe to re-run.
set -euo pipefail

MF=/etc/postfix/master.cf
MAP=/etc/postfix/sender_logins_submission.pcre
MARK='-o smtpd_sender_login_maps=pcre:/etc/postfix/sender_logins_submission.pcre'

# Seed the map if the panel has not synced mail yet: an empty map with only the
# catch-all is valid and keeps the submission service loadable.
if [ ! -f "$MAP" ]; then
    printf '%s\n' '/^.+$/ __cwhpanel_no_owner__' > "$MAP"
    chmod 0644 "$MAP"
    chown root:root "$MAP"
fi

python3 - "$MF" "$MARK" <<'PY'
import sys
mf, mark = sys.argv[1], sys.argv[2]
lines = open(mf).read().splitlines(keepends=True)
out = []
changed = False
i = 0
while i < len(lines):
    line = lines[i]
    out.append(line)
    if (line.startswith('submission ') or line.startswith('smtps ')) and mark not in line:
        j = i + 1
        has = False
        while j < len(lines) and (lines[j].startswith(' ') or lines[j].startswith('\t')):
            if mark in lines[j]:
                has = True
            j += 1
        if not has:
            out.append('    ' + mark + '\n')
            changed = True
    i += 1
if changed:
    open(mf, 'w').writelines(out)
print('master.cf updated' if changed else 'master.cf already has the submission map')
PY

postfix check
systemctl reload postfix
echo "postfix reloaded"
