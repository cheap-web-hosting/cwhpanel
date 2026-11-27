#!/usr/bin/env python3
"""
Postfix policy service: enforces outbound mail rate limits.

- per-mailbox per-hour limit (mailboxes.max_out_per_hour)
- per-account per-hour + per-day limits (accounts.mail_max_out_per_hour / _day)

Backed by the panel's MySQL DB. Listens on 127.0.0.1:POLICY_PORT and speaks the
Postfix policy delegation protocol (one request = key=value lines, blank line
terminator; one response = action=... lines).

Returns DUNNO when there is no authenticated sender (the user didn't auth, so
relaying is already blocked elsewhere); DEFER_IF_REJECT when a limit is hit.
"""
import os
import sys
import time
import pymysql

PORT = int(os.environ.get("CWH_POLICY_PORT", "10999"))
DB_HOST = os.environ.get("DB_HOST", "127.0.0.1")
DB_PORT = int(os.environ.get("DB_PORT", "3306"))
DB_NAME = os.environ.get("DB_DATABASE", "cwhpanel")
DB_USER = os.environ.get("DB_USERNAME", "root")
DB_PASS = os.environ.get("DB_PASSWORD", "")

def db():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, database=DB_NAME, user=DB_USER, password=DB_PASS,
        autocommit=True, cursorclass=pymysql.cursors.DictCursor,
    )

def check_limits(mailbox_email):
    """Return None if OK, else a (status, message) tuple."""
    now = int(time.time())
    hour_bucket = now - (now % 3600)
    day_bucket = now - (now % 86400)

    conn = db()
    cur = conn.cursor()

    # Resolve mailbox + account.
    cur.execute("SELECT m.id AS mailbox_id, m.account_id, m.max_out_per_hour, "
                "a.mail_max_out_per_hour, a.mail_max_out_per_day "
                "FROM mailboxes m JOIN accounts a ON a.id = m.account_id "
                "WHERE m.email = %s AND m.status = 'active'", (mailbox_email,))
    row = cur.fetchone()
    if row is None:
        cur.close(); conn.close(); return None  # unknown; let other checks handle it

    mailbox_id = row["mailbox_id"]
    account_id = row["account_id"]
    mb_hour = row["max_out_per_hour"]
    acct_hour = row["mail_max_out_per_hour"]
    acct_day = row["mail_max_out_per_day"]

    # Current counts.
    cur.execute("SELECT COUNT(*) c, COALESCE(SUM(count),0) s FROM mail_counters "
                "WHERE mailbox = %s AND bucket_ts = %s", (mailbox_email, hour_bucket))
    mb = cur.fetchone()
    mb_hour_count = mb["s"] or 0

    cur.execute("SELECT COUNT(*) c, COALESCE(SUM(count),0) s FROM mail_counters "
                "WHERE account_id = %s AND bucket_ts = %s", (account_id, hour_bucket))
    ac = cur.fetchone()
    acct_hour_count = ac["s"] or 0

    cur.execute("SELECT COALESCE(SUM(count),0) s FROM mail_counters "
                "WHERE account_id = %s AND bucket_ts >= %s", (account_id, day_bucket))
    ad = cur.fetchone()
    acct_day_count = ad["s"] or 0

    cur.close(); conn.close()

    if mb_hour and mb_hour_count >= mb_hour:
        return ("DEFER_IF_REJECT", "mailbox hourly limit reached")
    if acct_hour and acct_hour_count >= acct_hour:
        return ("DEFER_IF_REJECT", "account hourly limit reached")
    if acct_day and acct_day_count >= acct_day:
        return ("DEFER_IF_REJECT", "account daily limit reached")

    # Increment counters.
    conn = db(); cur = conn.cursor()
    cur.execute("INSERT INTO mail_counters (account_id, mailbox, bucket_ts, count) "
                "VALUES (%s,%s,%s,1) "
                "ON DUPLICATE KEY UPDATE count = count + 1",
                (account_id, mailbox_email, hour_bucket))
    cur.close(); conn.close()
    return None

def check_list_posting(sender, recipient):
    """Members-only mailing lists: reject non-member senders with 550.

    Runs for ALL mail (inbound included) since list posts usually arrive
    unauthenticated. Returns None if OK, else an (action, message) tuple.
    """
    rcpt = (recipient or "").strip().lower()
    if not rcpt or "@" not in rcpt:
        return None
    # Strip any +extension: Postfix resolves list+tag@domain to the same
    # virtual alias, but the literal lookup below would miss the list row and
    # skip the members-only check (bypass). Normalize first.
    _local, _, _dom = rcpt.partition("@")
    rcpt = _local.split("+", 1)[0] + "@" + _dom
    conn = db(); cur = conn.cursor()
    cur.execute("SELECT l.id FROM mailing_lists l JOIN mail_domains d ON d.id = l.domain_id "
                "WHERE l.status = 'active' AND d.status = 'active' AND l.posting = 'members' "
                "AND LOWER(CONCAT(l.name, '@', d.domain)) = %s LIMIT 1", (rcpt,))
    row = cur.fetchone()
    if row is None:
        cur.close(); conn.close(); return None
    snd = (sender or "").strip().lower()
    if not snd or snd == "<>":
        cur.close(); conn.close()
        return ("REJECT", "posting to this list is restricted to members")
    cur.execute("SELECT COUNT(*) c FROM mailing_list_members "
                "WHERE list_id = %s AND status = 'active' AND LOWER(email) = %s",
                (row["id"], snd))
    ok = (cur.fetchone() or {}).get("c", 0) > 0
    cur.close(); conn.close()
    if ok:
        return None
    return ("REJECT", "posting to this list is restricted to members")

MAX_REQUEST_LINES = 64
MAX_FIELD_LEN = 1024

def parse_request(f):
    """Parse a postfix policy request.

    Bounded memory: at most MAX_REQUEST_LINES lines, each read capped at
    4096 bytes and each field value capped at MAX_FIELD_LEN. An oversized
    request returns None (caller closes the connection), so a local flood
    can never exhaust the daemon's memory.
    """
    req = {}
    lines = 0
    while True:
        line = f.readline(4096)
        if line in ("", "\n", b"", b"\n"):
            break
        lines += 1
        if lines > MAX_REQUEST_LINES:
            return None
        if isinstance(line, bytes):
            line = line.decode(errors="replace")
        if len(line) > 4096 and not line.endswith("\n"):
            return None
        if "=" in line:
            k, v = line.strip().split("=", 1)
            if len(k) > 256 or len(v) > MAX_FIELD_LEN:
                continue
            req[k] = v
    return req

def respond(f, action, text=""):
    # Per SMTPD_POLICY_README the custom text rides on the action line
    # ("action=REJECT reason"); a separate status= line is ignored and
    # postfix falls back to its default ("Access denied").
    if text:
        f.write("action=%s %s\n" % (action, text))
    else:
        f.write("action=%s\n" % action)
    f.write("\n")
    f.flush()

def handle(f):
    req = parse_request(f)
    if req is None:
        # Oversized/malformed request: drop the connection. The client will
        # see a transport error and retry; the daemon stays bounded.
        return
    user = req.get("sasl_username", "")
    sender = req.get("sender", "")

    # Members-only mailing lists first: list posts usually arrive
    # unauthenticated, so this must run before the auth gate below.
    try:
        verdict = check_list_posting(sender, req.get("recipient", ""))
    except Exception as e:
        sys.stderr.write("list policy error: %s\n" % e)
        verdict = None
    if verdict is not None:
        action, msg = verdict
        respond(f, action, msg); return

    # Only rate-limit authenticated (Dovecot SASL) submissions.
    if not user:
        respond(f, "DUNNO"); return
    # Null-envelope (MAIL FROM:<>) submissions from authenticated sessions
    # are counted against the same per-mailbox caps — skipping them would
    # give an authenticated account an unbounded, cap-bypassing channel.
    try:
        result = check_limits(user)
    except Exception as e:
        sys.stderr.write("policy error: %s\n" % e)
        respond(f, "DUNNO"); return

    if result is None:
        respond(f, "DUNNO")
    else:
        action, msg = result
        respond(f, action, msg)

def serve_conn(conn):
    try:
        conn.settimeout(10)  # a stalled client can never pin a worker forever
        f = conn.makefile("rw")
        handle(f)
    except Exception as e:
        sys.stderr.write("conn error: %s\n" % e)
    finally:
        try: conn.close()
        except Exception: pass

def main():
    import socket, time
    from concurrent.futures import ThreadPoolExecutor
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", PORT))
    srv.listen(128)
    srv.settimeout(1.0)  # periodic wakeup so a signal/stop is honoured
    sys.stderr.write("mail-policy listening on 127.0.0.1:%d\n" % PORT)
    # Bounded worker pool: a loopback flood (hosting accounts have loopback
    # TCP) can never pin a thread per connection and exhaust the daemon,
    # which would tempfail outbound mail server-wide.
    pool = ThreadPoolExecutor(max_workers=32)
    accept_times = []
    while True:
        try:
            conn, _ = srv.accept()
        except socket.timeout:
            continue
        except Exception as e:
            sys.stderr.write("accept error: %s\n" % e)
            continue
        # Simple connection rate throttle: at most 200 accepted connections
        # per rolling 10s. Above that, back off briefly — the flood is
        # dropped at accept time, not inside the pool.
        now = time.monotonic()
        accept_times = [t for t in accept_times if now - t < 10.0]
        if len(accept_times) >= 200:
            time.sleep(0.25)
            try:
                conn.close()
            except Exception:
                pass
            continue
        accept_times.append(now)
        pool.submit(serve_conn, conn)

if __name__ == "__main__":
    main()