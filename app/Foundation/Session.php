<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Foundation\Database\Connection;
use SessionHandlerInterface;

class Session implements SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private Connection $db;

    private string $table;

    private bool $started = false;

    public function __construct(Connection $db, string $table = 'sessions')
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        // Never attempt to (re)configure the session after it is already active
        // or after output has begun — both are fatal in strict error handling.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if (headers_sent()) {
            // Output already started (e.g. a stray byte emitted during bootstrap);
            // initialize $_SESSION defensively so the rest of the request can
            // read/write session state without an "undefined global" fatal.
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            $this->started = true;
            return;
        }

        $this->configureCookies();

        try {
            session_set_save_handler($this, true);
        } catch (\Throwable $e) {
            // Handler already registered elsewhere; continue with defaults.
        }

        session_name($this->cookieName());

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // Reject attacker-preset session ids (session fixation defense).
        @ini_set('session.use_strict_mode', '1');

        // Keep server-side GC aligned with the configured session lifetime.
        // PHP's default session.gc_maxlifetime (1440s) is far shorter than the
        // panel's configured lifetime, which silently deleted idle sessions
        // after ~24 minutes — users were logged out long before the advertised
        // 120-minute window.
        @ini_set('session.gc_maxlifetime', (string) ((int) config('session.lifetime', 120) * 60));

        if (!@session_start()) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
        }

        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function id(): string
    {
        return session_id();
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function get(string $key, $default = null)
    {
        if (array_key_exists($key, $_SESSION ?? [])) {
            return $_SESSION[$key];
        }

        return ($_SESSION['_flash']['old'][$key] ?? $default);
    }

    public function put(string $key, $value): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return false;
        }
        return array_key_exists($key, $_SESSION)
            || array_key_exists($key, $_SESSION['_flash']['old'] ?? []);
    }

    public function forget(string $key): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        unset($_SESSION[$key]);
        unset($_SESSION['_flash']['old'][$key]);
    }

    public function pull(string $key, $default = null)
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function flash(string $key, $value): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['_flash']['new'][$key] = $value;
    }

    public function now(string $key, $value): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['_flash']['old'][$key] = $value;
    }

    public function reflash(): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['_flash']['new'] = array_merge(
            $_SESSION['_flash']['old'] ?? [],
            $_SESSION['_flash']['new'] ?? []
        );
    }

    public function ageFlash(): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['_flash']['old'] = $_SESSION['_flash']['new'] ?? [];
        $_SESSION['_flash']['new'] = [];
    }

    public function old(string $key, $default = '')
    {
        return $_SESSION['_flash']['old']['_old'][$key] ?? $default;
    }

    public function errors(): array
    {
        return $_SESSION['_flash']['old']['_errors'] ?? [];
    }

    public function pushError(string $field, string $message): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['_flash']['new']['_errors'][$field][] = $message;
    }

    public function flashInput(array $input): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['_flash']['new']['_old'] = $input;
    }

    public function flush(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    private function configureCookies(): void
    {
        $secure = $this->resolveSecureFlag();
        $lifetime = (int) config('session.lifetime', 120);

        // Once the session has started (or any output has been emitted), cookie
        // parameters can no longer be changed. This is best-effort configuration
        // only — never fail the request because of it.
        if (headers_sent()) {
            return;
        }

        @session_set_cookie_params([
            'lifetime' => $lifetime * 60,
            'path' => '/',
            'domain' => config('session.domain', ''),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => config('session.same_site', 'Lax'),
        ]);
    }

    /**
     * Resolve the session-cookie Secure flag.
     *
     * 'auto' (default) asserts Secure whenever the panel URL is https, so a
     * TLS deployment (provision-hostname-tls.php sets APP_URL=https://…)
     * hardens the cookie with zero manual steps. Explicit true/false in
     * SESSION_SECURE_COOKIE always wins (dev servers stay http-usable).
     */
    /**
     * Split-panel sessions use DISTINCT cookie names. Both panels share the
     * host, so one cookie meant a customer login rotated the admin session and
     * the next admin POST failed CSRF ("session expired or the security token
     * is invalid") — indistinguishable from being logged out.
     */
    private function cookieName(): string
    {
        $name = (string) config('session.name', 'panel_session');
        $adminPort = (int) config('app.admin_port', 0);
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        if ($adminPort > 0 && $port === $adminPort) {
            return $name . '_admin';
        }
        return $name;
    }

    private function resolveSecureFlag(): bool
    {
        $raw = config('session.secure', 'auto');

        if (is_bool($raw)) {
            return $raw;
        }

        $normalized = strtolower(trim((string) $raw));
        if ($normalized === 'true' || $normalized === '1' || $normalized === 'on') {
            return true;
        }
        if ($normalized === 'false' || $normalized === '0' || $normalized === 'off' || $normalized === '') {
            return false;
        }

        // 'auto' or anything unrecognized: follow the panel URL scheme.
        return str_starts_with(strtolower((string) config('app.url', '')), 'https://');
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * PHP 9 requires create_sid() on SessionHandlerInterface implementations.
     * PHP 8.6 already deprecates its absence (fatal under the panel's
     * ExceptionHandler), so generate the id ourselves rather than relying on
     * the engine's default.
     */
    public function create_sid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * PHP only consults this when the handler implements
     * SessionUpdateTimestampHandlerInterface — without that interface the
     * method was DEAD CODE and `session.use_strict_mode=1` could not reject an
     * unknown client-supplied id (any well-formed id was adopted). It must
     * check for a live row now, not just the shape.
     */
    public function validateId(string $id): bool
    {
        if (preg_match('/^[A-Za-z0-9,-]{16,256}$/', $id) !== 1) {
            return false;
        }
        try {
            $cutoff = time() - ((int) config('session.lifetime', 120) * 60);
            return $this->db->selectOne(
                "SELECT `id` FROM `{$this->table}` WHERE `id` = ? AND `last_activity` > ?",
                [$id, $cutoff]
            ) !== null;
        } catch (\Throwable $e) {
            // Fail closed: an unknown id is replaced by a fresh one.
            app('logger')->error('Session validate failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * PHP calls this instead of write() when the payload did not change, so it
     * MUST refresh the sliding window or an idle-but-active session would be
     * reaped at the old last_activity stamp.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $this->db->statement(
                "UPDATE `{$this->table}` SET `last_activity` = ? WHERE `id` = ?",
                [time(), $id]
            );
        } catch (\Throwable $e) {
            app('logger')->error('Session touch failed: ' . $e->getMessage());
        }
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $cutoff = time() - ((int) config('session.lifetime', 120) * 60);
            $row = $this->db->selectOne(
                // Both a sliding window (last_activity) AND an absolute expiry
                // (created_at): a stolen session cookie cannot stay alive
                // forever just by being replayed before the window closes.
                //
                // BOTH columns are INT unix timestamps (AddSessionCreatedAt), so
                // the bound value must be the int too: binding a
                // 'Y-m-d H:i:s' string let MySQL coerce it to 2026 and the
                // absolute-expiry predicate was silently always-true.
                "SELECT `payload` FROM `{$this->table}` WHERE `id` = ? AND `last_activity` > ? AND `created_at` > ?",
                [$id, $cutoff, $cutoff]
            );

            return $this->decryptPayload((string) ($row['payload'] ?? ''));
        } catch (\Throwable $e) {
            // A failed read must never silently masquerade as "nobody is
            // logged in" — log it so session-loss events are visible.
            app('logger')->error('Session read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $now = time();

            // Attribute the row while we have the payload: user_id drives
            // the "active sessions" list (WHERE user_id = ? instead of
            // decoding every payload), IP/agent identify the device. The
            // sessions.user_id column stays NULL for logged-out rows.
            $this->db->statement(
                // created_at is the absolute-expiry anchor and is only ever set
                // on first insert (COALESCE keeps the original first-seen time
                // across the sliding-window touch).
                "INSERT INTO `{$this->table}` (`id`, `payload`, `last_activity`, `user_id`, `ip_address`, `user_agent`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `last_activity` = VALUES(`last_activity`),
                     `user_id` = VALUES(`user_id`), `ip_address` = VALUES(`ip_address`), `user_agent` = VALUES(`user_agent`),
                     `created_at` = COALESCE(`created_at`, VALUES(`created_at`))",
                [$id, $this->encryptPayload($data), $now, self::payloadUserId($data), self::clientIp(), self::clientAgent(), $now]
            );
        } catch (\Throwable $e) {
            // Session persistence is best-effort; never fail the request because
            // of it — but log, so a DB outage is diagnosable instead of looking
            // like "nobody is logged in".
            app('logger')->error('Session write failed: ' . $e->getMessage());
        }

        return true;
    }

    /** 32-byte key derived from APP_KEY for session-payload encryption. */
    private function sessionKey(): string
    {
        $key = (string) config('app.key', '');
        if ($key === '') {
            // Never silently fall back to a public constant key: that would
            // make the "encrypted" payload decryptable by anyone with DB read
            // and forgeable with DB write. Fail loudly instead.
            throw new \RuntimeException('APP_KEY is not configured. Set a random APP_KEY in .env (scripts/install.php generates one).');
        }
        return hash('sha256', 'cwhpanel-session|' . $key, true);
    }

    /**
     * Encrypt the stored session payload. The DB row could otherwise expose
     * the built-in webmail's mailbox password (and any flashed secret) to a
     * DB read. Prefixed so legacy plaintext rows still decode.
     */
    private function encryptPayload(string $plain): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'enc1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->sessionKey()));
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $ct = openssl_encrypt($plain, 'aes-256-gcm', $this->sessionKey(), OPENSSL_RAW_DATA, $iv, $tag);
            if (is_string($ct)) {
                return 'enc2:' . base64_encode($iv . $tag . $ct);
            }
        }
        // FAIL CLOSED: session payloads can carry mailbox passwords and CSRF
        // state. Silently storing them in plaintext is worse than an outage.
        throw new \RuntimeException(
            'Session encryption unavailable: install the sodium or openssl PHP extension.'
        );
    }

    private function decryptPayload(string $stored): string
    {
        if (str_starts_with($stored, 'enc1:') && function_exists('sodium_crypto_secretbox_open')) {
            $raw = base64_decode(substr($stored, 5), true);
            if (!is_string($raw) || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ct = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($ct, $nonce, $this->sessionKey());
            return $plain === false ? '' : $plain;
        }
        if (str_starts_with($stored, 'enc2:') && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($stored, 5), true);
            if (!is_string($raw) || strlen($raw) < 28) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ct = substr($raw, 28);
            $plain = openssl_decrypt($ct, 'aes-256-gcm', $this->sessionKey(), OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }
        return $stored; // legacy plaintext row
    }

    /**
     * Extract the logged-in user id from PHP's native session encoding
     * (entries of `name|<serialized>;`). Auth::completeLogin is the only
     * writer of the `user_id` key. Parsed entry-by-entry (length-aware
     * string skips) rather than regexed, so the same bytes inside a
     * flashed form value can never misattribute a row to another user.
     * Null when logged out or undecodable — never throw here.
     */
    public static function payloadUserId(string $data): ?int
    {
        try {
            $pos = 0;
            $len = strlen($data);
            $userId = null;
            while ($pos < $len) {
                $pipe = strpos($data, '|', $pos);
                if ($pipe === false) {
                    break;
                }
                $name = substr($data, $pos, $pipe - $pos);
                if (!preg_match('/^\w+$/', $name)) {
                    break;
                }
                $pos = $pipe + 1;
                $value = self::parseSessionValue($data, $pos);
                // Values are self-terminating (scalars past their `;`,
                // containers past `}`) — the next entry starts at $pos.
                if ($name === 'user_id' && is_int($value) && $value > 0) {
                    $userId = $value;
                }
            }
            return $userId;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Parse one PHP-serialized value at $pos (by reference, advanced just
     * past the value INCLUDING any scalar `;` terminator — containers end
     * at their closing `}`, scalars past their `;`). Throws on anything
     * unparseable; callers treat that as "stop, undecodable".
     */
    private static function parseSessionValue(string $data, int &$pos): mixed
    {
        $len = strlen($data);
        if ($pos >= $len) {
            throw new \RuntimeException('truncated');
        }
        $type = $data[$pos];
        if ($type === 'N' && ($data[$pos + 1] ?? '') === ';') {
            $pos += 2;
            return null;
        }
        if ($type === 'i' || $type === 'b' || $type === 'd') {
            $end = strpos($data, ';', $pos);
            if ($end === false) {
                throw new \RuntimeException('truncated');
            }
            $raw = substr($data, $pos + 2, $end - $pos - 2);
            $pos = $end + 1;
            return $type === 'i' ? (int) $raw : ($type === 'b' ? $raw === '1' : (float) $raw);
        }
        if ($type === 's') {
            $colon = strpos($data, ':', $pos + 2);
            if ($colon === false) {
                throw new \RuntimeException('bad string');
            }
            $strLen = (int) substr($data, $pos + 2, $colon - $pos - 2);
            $open = $colon + 2; // skip :"
            if (($data[$open - 1] ?? '') !== '"' || $strLen < 0 || $open + $strLen + 2 > $len) {
                throw new \RuntimeException('bad string');
            }
            $out = substr($data, $open, $strLen);
            $pos = $open + $strLen + 2; // skip content";
            return $out;
        }
        if ($type === 'a' || $type === 'O') {
            $brace = strpos($data, '{', $pos);
            if ($brace === false) {
                throw new \RuntimeException('truncated');
            }
            $count = (int) substr($data, $pos + 2, $brace - $pos - 2);
            if ($count < 0) {
                throw new \RuntimeException('bad count');
            }
            $pos = $brace + 1;
            $out = $type === 'a' ? [] : new \stdClass();
            // One iteration per element; each element is a key AND a value,
            // each self-terminating (no separators between them).
            for ($i = 0; $i < $count; $i++) {
                $k = self::parseSessionValue($data, $pos);
                $v = self::parseSessionValue($data, $pos);
                if ($type === 'a') {
                    $out[is_int($k) ? $k : (string) $k] = $v;
                }
            }
            if (($data[$pos] ?? '') !== '}') {
                throw new \RuntimeException('bad close');
            }
            $pos++;
            return $out;
        }
        if (($type === 'R' || $type === 'r')) {
            $end = strpos($data, ';', $pos);
            if ($end === false) {
                throw new \RuntimeException('truncated');
            }
            $pos = $end + 1;
            return 0;
        }
        throw new \RuntimeException('unknown type');
    }

    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? mb_substr($ip, 0, 45) : null;
    }

    private static function clientAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) && $ua !== '' ? mb_substr($ua, 0, 255) : null;
    }

    public function destroy(string $id): bool
    {
        try {
            $this->db->delete($this->table, '`id` = ?', [$id]);
        } catch (\Throwable $e) {
        }

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // The PHP engine passes session.gc_maxlifetime here; use the panel's
        // configured lifetime so GC matches the read() sliding/absolute window
        // exactly (see start(): gc_maxlifetime is also pinned to config).
        $cutoff = time() - ((int) config('session.lifetime', 120) * 60);
        try {
            $deleted = $this->db->statement(
                "DELETE FROM `{$this->table}` WHERE `last_activity` < ?",
                [$cutoff]
            )->rowCount();
        } catch (\Throwable $e) {
            app('logger')->error('Session GC failed: ' . $e->getMessage());
            // A failing GC must not kill session_start(); false = "didn't run".
            return false;
        }

        // MUST be int|false: returning true made PHP throw a TypeError inside
        // session_start() whenever the engine rolled GC (gc_probability 1%),
        // so ~1 request in 100 ran with NO session — a login POST then lost its
        // CSRF token and the page came back with no error (bitten live: "had to
        // sign in twice, the first time nothing happened").
        return $deleted;
    }
}
