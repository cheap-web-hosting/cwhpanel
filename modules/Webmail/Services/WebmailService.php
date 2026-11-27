<?php
declare(strict_types=1);
namespace Modules\Webmail\Services;

/**
 * Minimal self-hosted webmail backed by the panel's own mail stack.
 *
 * Read: Dovecot IMAP on 127.0.0.1:143 (the agent's `mail.setup` mail_location
 *       Maildir users live here). Password auth reuses the mailbox password,
 *       which the panel stores only as a Dovecot {CRYPT} bcrypt hash — so the
 *       user supplies it at webmail login and it is held in the DB-backed,
 *       HttpOnly/SameSite session for the duration of the browsing session.
 *
 * Write: SMTP submission to 127.0.0.1:587 (Postfix sasl/dovecot). Uses a tiny
 *        AUTH PLAIN client; no PHPMailer/third-party dependency.
 */
class WebmailService
{
    public const IMAP_HOST = '127.0.0.1';
    public const IMAP_PORT = 143;
    public const SMTP_HOST = '127.0.0.1';
    public const SMTP_PORT = 587;

    public const SESSION_KEY = 'webmail';

    // ------------------------------------------------------------------ IMAP

    /**
     * The Dovecot master password (for SSO). Empty when not configured.
     */
    public static function masterPassword(): string
    {
        return (string) env('MAIL_MASTER_PASSWORD', '');
    }

    /**
     * The master-auth login username for a target mailbox:
     * "<email>*master@panel" (Dovecot auth_master_user_separator = "*").
     */
    public static function masterUsername(string $email): string
    {
        return $email . '*master@panel';
    }

    /**
     * Open an IMAP stream as a given mailbox using Dovecot master-user auth.
     * This lets the panel (acting as master@panel) access a mailbox without
     * knowing its password — the SSO path for "Open Webmail".
     *
     * @throws \RuntimeException
     */
    public function openAsUser(string $email)
    {
        $master = self::masterPassword();
        if ($master === '') {
            throw new \RuntimeException('Webmail single sign-on is not configured.');
        }

        return $this->open(self::masterUsername($email), $master);
    }

    /**
     * Open an IMAP stream for the given mailbox/epassword. Returns a resource.
     * @throws \RuntimeException
     */
    public function open(string $username, string $password)
    {
        $mailbox = sprintf(
            '{%s:%d/notls/novalidate-cert}INBOX',
            self::IMAP_HOST,
            self::IMAP_PORT
        );

        $stream = @imap_open($mailbox, $username, $password, 0, 1, []);
        if ($stream === false) {
            throw new \RuntimeException('IMAP login failed: ' . trim((string) imap_last_error()));
        }

        return $stream;
    }

    /**
     * List folders for a stream. Returns [{name, delimiter, unread, total}].
     */
    public function folders($stream): array
    {
        $folders = [];
        $list = @imap_list($stream, '{' . self::IMAP_HOST . '}', '*');
        if ($list === false) {
            $list = [];
        }
        // Always include INBOX.
        $seen = [];
        foreach (['INBOX'] as $base) {
            $stat = @imap_status($stream, '{' . self::IMAP_HOST . '}' . $base, SA_ALL);
            $folders[] = $this->folderRow($base, '/', $stat);
            $seen[$base] = true;
        }
        foreach ($list as $m) {
            $name = $this->folderName($m);
            if ($name === '' || $name === 'INBOX' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $stat = @imap_status($stream, $m, SA_ALL);
            $folders[] = $this->folderRow($name, '/', $stat);
        }

        return $folders;
    }

    private function folderName(string $mailbox): string
    {
        // Strip the {host} prefix.
        $pos = strpos($mailbox, '}');
        return $pos !== false ? substr($mailbox, $pos + 1) : $mailbox;
    }

    private function folderRow(string $name, string $delimiter, $stat): array
    {
        return [
            'name' => $name,
            'delimiter' => $delimiter,
            'messages' => is_object($stat) ? (int) ($stat->messages ?? 0) : 0,
            'unread' => is_object($stat) ? (int) ($stat->unseen ?? 0) : 0,
        ];
    }

    /**
     * Fetch a page of message headers from a folder. Order newest-first.
     * @return array<int, array{uid,subject,from,date,seen,size}>
     */
    public function headers($stream, string $folder, int $page = 1, int $perPage = 25): array
    {
        $this->selectFolder($stream, $folder);
        $count = @imap_num_msg($stream);
        if ($count < 1) {
            return ['total' => 0, 'page' => 1, 'pages' => 0, 'messages' => []];
        }

        // Sequence numbers 1..N are oldest..newest; page over reversed order.
        $messages = [];
        $start = $count - (($page - 1) * $perPage);
        $end = max(1, $start - $perPage + 1);

        if ($start < 1) {
            $start = 1;
        }

        for ($i = $start; $i >= $end; $i--) {
            $overview = @imap_fetch_overview($stream, (string) $i, 0);
            if ($overview === false || !isset($overview[0])) {
                continue;
            }
            $o = $overview[0];
            $hdr = isset($o->from) ? $this->decodeAddress($o->from) : '';
            $messages[] = [
                'uid' => (int) $o->uid,
                'seq' => $i,
                'subject' => isset($o->subject) ? $this->decodeMime($o->subject) : '(no subject)',
                'from' => $this->decodeHeader($o->from ?? ''),
                'date' => isset($o->date) ? date('M j, Y H:i', strtotime($o->date)) : '',
                'seen' => isset($o->seen) && $o->seen === 1,
                'size' => isset($o->size) ? (int) $o->size : 0,
            ];
        }

        $pages = (int) ceil($count / $perPage);

        return ['total' => $count, 'page' => $page, 'pages' => $pages, 'messages' => $messages];
    }

    /**
     * Select a folder on an open stream. The folder name is validated against
     * the account's real folder list so a crafted `folder` parameter can never
     * become an arbitrary mailbox reference.
     * @throws \RuntimeException
     */
    public function selectFolder($stream, string $folder): void
    {
        $valid = false;
        foreach ($this->folders($stream) as $f) {
            if ($f['name'] === $folder) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new \RuntimeException('Unknown folder.');
        }
        $mailbox = '{' . self::IMAP_HOST . '}' . ($folder === 'INBOX' ? 'INBOX' : $folder);
        if (@imap_reopen($stream, $mailbox) === false) {
            throw new \RuntimeException('Could not open folder.');
        }
    }

    /**
     * Full-text search within a folder (IMAP TEXT criterion). Returns the same
     * shape as headers(). Newest matches first; paginated in PHP.
     */
    public function search($stream, string $folder, string $query, int $page = 1, int $perPage = 25): array
    {
        $query = trim($query);
        $this->selectFolder($stream, $folder);
        if ($query === '') {
            return $this->headers($stream, $folder, $page, $perPage);
        }
        $crit = 'TEXT "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $query) . '"';
        $uids = @imap_search($stream, $crit, SE_UID);
        if ($uids === false || $uids === []) {
            return ['total' => 0, 'page' => 1, 'pages' => 0, 'messages' => []];
        }
        rsort($uids, SORT_NUMERIC);
        $total = count($uids);
        $pages = (int) ceil($total / $perPage);
        $page = max(1, min($page, $pages));
        $messages = [];
        foreach (array_slice($uids, ($page - 1) * $perPage, $perPage) as $uid) {
            $overview = @imap_fetch_overview($stream, (string) $uid, FT_UID);
            if ($overview === false || !isset($overview[0])) {
                continue;
            }
            $o = $overview[0];
            $messages[] = [
                'uid' => (int) $o->uid,
                'seq' => 0,
                'subject' => isset($o->subject) ? $this->decodeMime($o->subject) : '(no subject)',
                'from' => $this->decodeHeader($o->from ?? ''),
                'date' => isset($o->date) ? date('M j, Y H:i', strtotime($o->date)) : '',
                'seen' => isset($o->seen) && $o->seen === 1,
                'size' => isset($o->size) ? (int) $o->size : 0,
            ];
        }

        return ['total' => $total, 'page' => $page, 'pages' => $pages, 'messages' => $messages];
    }

    /**
     * Move a message (by UID) from one folder to another. Returns void.
     * @throws \RuntimeException
     */
    public function move($stream, int $uid, string $fromFolder, string $toFolder): void
    {
        if ($uid < 1) {
            throw new \RuntimeException('Invalid message.');
        }
        $this->selectFolder($stream, $fromFolder);
        $dest = null;
        foreach ($this->folders($stream) as $f) {
            if ($f['name'] === $toFolder) {
                $dest = $f['name'];
                break;
            }
        }
        if ($dest === null) {
            throw new \RuntimeException('Unknown destination folder.');
        }
        if ($dest === $fromFolder) {
            throw new \RuntimeException('Message is already in that folder.');
        }
        if (@imap_mail_move($stream, (string) $uid, $dest, CP_UID) === false) {
            throw new \RuntimeException('Could not move message.');
        }
        @imap_expunge($stream);
    }

    /**
     * Permanently delete every message in a folder. Returns the count removed.
     */
    public function emptyFolder($stream, string $folder): int
    {
        $this->selectFolder($stream, $folder);
        $uids = @imap_search($stream, 'ALL', SE_UID);
        if ($uids === false || $uids === []) {
            return 0;
        }
        foreach ($uids as $u) {
            @imap_delete($stream, (string) $u, FT_UID);
        }
        @imap_expunge($stream);
        return count($uids);
    }

    /**
     * Best-effort Trash folder name for the account, or null when none exists.
     */
    public function trashFolder($stream): ?string
    {
        $names = [];
        foreach ($this->folders($stream) as $f) {
            $names[] = $f['name'];
        }
        foreach (['Trash', 'Deleted Messages', 'Deleted'] as $candidate) {
            foreach ($names as $n) {
                if (strcasecmp($n, $candidate) === 0) {
                    return $n;
                }
            }
        }
        return null;
    }

    /**
     * Fetch a full message body by UID. Returns [subject,from,to,date,text,html,attachments].
     */
    public function message($stream, int $uid): array
    {
        $seq = @imap_msgno($stream, $uid);
        if ($seq < 1) {
            throw new \RuntimeException('Message not found.');
        }

        $header = @imap_fetchheader($stream, $seq, FT_UID);

        $text = '';
        $html = '';
        $attachments = [];

        $structure = @imap_fetchstructure($stream, $seq, FT_UID);
        if ($structure !== false && !empty($structure->parts)) {
            $parsed = $this->extractParts($stream, $seq, $structure, '');
            $text = $parsed['text'];
            $html = $parsed['html'];
            $attachments = $parsed['attachments'];
        } else {
            // Single-part message (or no parsable structure).
            $encoding = $structure->encoding ?? 0;
            $subtype = strtolower((string) ($structure->subtype ?? 'plain'));
            $raw = @imap_fetchbody($stream, $seq, '1', FT_UID);
            if ($raw === false) {
                $raw = @imap_body($stream, $seq, FT_UID);
            }
            if ($raw !== false) {
                $decoded = $this->decodeTransfer((int) $encoding, $raw);
                $decoded = $this->decodeMime($decoded);
                if ($subtype === 'html') {
                    $html = $decoded;
                } else {
                    $text = $decoded;
                }
            }
        }

        // Mark seen.
        @imap_setflag_full($stream, (string) $seq, '\\Seen', ST_UID);

        // Reliable envelope headers (structure->from/to/subject are often empty).
        $hdr = @imap_headerinfo($stream, $seq);
        $subject = '';
        $from = '';
        $to = '';
        $date = '';
        if ($hdr !== false) {
            $subject = isset($hdr->subject) ? $this->decodeMime($hdr->subject) : '';
            $from = $this->formatAddressList($hdr->from ?? []);
            $to = $this->formatAddressList($hdr->to ?? []);
            $date = $hdr->date ?? '';
        }
        if ($subject === '') {
            $subject = isset($structure->subject) ? $this->decodeHeader((string) $structure->subject) : '';
        }

        return [
            'uid' => $uid,
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'from' => $from,
            'to' => $to,
            'date' => $date !== '' ? date('M j, Y H:i', strtotime($date)) : '',
            'text' => $text,
            'html' => $this->sanitizeHtml($html),
            'attachments' => $attachments,
        ];
    }

    /**
     * Sanitize an email's HTML part before it is echoed into the panel page.
     * The part is fully attacker-controlled (any sender), so strip scripting,
     * framing, forms, meta/base refresh and event-handler/dangerous-URL
     * attributes. CSP already blocks inline script; this removes the
     * HTML/CSS-injection and meta-refresh/phishing surface too.
     */
    private function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $badTags = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
            'form', 'input', 'button', 'textarea', 'select', 'option', 'meta', 'base', 'link',
            'svg', 'math', 'template', 'noscript'];

        if (!class_exists(\DOMDocument::class)) {
            $alt = implode('|', $badTags);
            $html = (string) preg_replace('#<\s*(' . $alt . ')\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
            $html = (string) preg_replace('#<\s*/?\s*(' . $alt . ')\b[^>]*>#is', '', $html);
            $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
            $html = (string) preg_replace('#\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
            $html = (string) preg_replace('#(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '$1="#"', $html);
            return $html;
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*') ?: [] as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }
            if (in_array(strtolower($node->nodeName), $badTags, true)) {
                $node->parentNode?->removeChild($node);
                continue;
            }
            if ($node->hasAttributes()) {
                for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
                    $attr = $node->attributes->item($i);
                    if ($attr === null) {
                        continue;
                    }
                    $name = strtolower($attr->name);
                    if (str_starts_with($name, 'on')) {
                        $node->removeAttribute($attr->name);
                        continue;
                    }
                    // Drop style attributes entirely: retained CSS enables
                    // UI-redress/phishing overlays inside the panel origin.
                    if ($name === 'style') {
                        $node->removeAttribute($attr->name);
                        continue;
                    }
                    if (($name === 'href' || $name === 'src' || $name === 'xlink:href' || $name === 'background')
                        && preg_match('#^\s*(javascript|vbscript|data):#i', (string) $attr->value)
                    ) {
                        $node->removeAttribute($attr->name);
                    }
                }
            }
        }

        $out = $doc->saveHTML();
        return is_string($out) ? $out : '';
    }

    /**
     * Fetch the full raw RFC822 source of a message by UID (for Bayesian training).
     */
    public function rawMessage($stream, int $uid): string
    {
        $seq = @imap_msgno($stream, $uid);
        if ($seq < 1) {
            throw new \RuntimeException('Message not found.');
        }
        $header = @imap_fetchheader($stream, $seq, FT_UID);
        $body = @imap_body($stream, $seq, FT_UID);
        $header = $header === false ? '' : (string) $header;
        $body = $body === false ? '' : (string) $body;
        return $header . "\r\n" . $body;
    }

    /**
     * Fetch one attachment's decoded bytes by MIME section (e.g. "2", "1.2").
     * The section string is strictly validated; the stream must already have
     * the message's folder selected. Returns [name, type, bytes].
     * @throws \RuntimeException
     */
    public function attachment($stream, int $uid, string $section): array
    {
        if ($uid < 1 || !preg_match('/^\d+(\.\d+)*$/', $section)) {
            throw new \RuntimeException('Invalid attachment.');
        }
        $seq = @imap_msgno($stream, $uid);
        if ($seq < 1) {
            throw new \RuntimeException('Message not found.');
        }
        $structure = @imap_fetchstructure($stream, $seq, FT_UID);
        if ($structure === false) {
            throw new \RuntimeException('Message not found.');
        }
        $meta = $this->findPart($structure, $section);
        if ($meta === null) {
            throw new \RuntimeException('Attachment not found.');
        }
        $raw = @imap_fetchbody($stream, $seq, $section, FT_UID);
        if ($raw === false) {
            throw new \RuntimeException('Attachment not found.');
        }
        $bytes = $this->decodeTransfer((int) ($meta['encoding'] ?? 0), $raw);
        if (strlen($bytes) > 20 * 1024 * 1024) {
            throw new \RuntimeException('Attachment too large to download.');
        }
        return ['name' => $meta['name'], 'type' => $meta['type'], 'bytes' => $bytes];
    }

    /**
     * Walk a fetched structure to the part at $section. Returns
     * [name, type, encoding] or null. Mirrors extractParts() numbering.
     */
    private function findPart($struct, string $section): ?array
    {
        $path = explode('.', $section);
        $node = $struct;
        foreach ($path as $idx) {
            $i = (int) $idx - 1;
            if (empty($node->parts) || !isset($node->parts[$i])) {
                return null;
            }
            $node = $node->parts[$i];
        }
        $filename = '';
        if (isset($node->dparameters)) {
            foreach ($node->dparameters as $dp) {
                if (strtolower($dp->attribute ?? '') === 'filename') {
                    $filename = (string) ($dp->value ?? '');
                }
            }
        }
        if ($filename === '' && isset($node->parameters)) {
            foreach ($node->parameters as $p) {
                if (strtolower($p->attribute ?? '') === 'name') {
                    $filename = (string) ($p->value ?? '');
                }
            }
        }
        return [
            'name' => $this->decodeMime($filename !== '' ? $filename : 'attachment'),
            'type' => $this->mimePrimary($node->type ?? 0) . '/' . strtolower((string) ($node->subtype ?? '')),
            'encoding' => $node->encoding ?? 0,
        ];
    }

    /**
     * Map an imap_fetchstructure numeric primary type to its MIME name.
     */
    private function mimePrimary($t): string
    {
        static $map = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model'];
        $i = is_int($t) ? $t : (is_numeric((string) $t) ? (int) $t : -1);
        return ($i >= 0 && $i < count($map)) ? $map[$i] : 'application';
    }

    private function formatAddressList(array $list): string
    {
        $out = [];
        foreach ($list as $a) {
            $personal = isset($a->personal) ? $this->decodeMime((string) $a->personal) : '';
            $mailbox = $a->mailbox ?? '';
            $host = $a->host ?? '';
            $addr = $mailbox !== '' ? $mailbox . '@' . $host : '';
            if ($personal !== '') {
                $out[] = $personal . ' <' . $addr . '>';
            } else {
                $out[] = $addr;
            }
        }
        return implode(', ', $out);
    }

    private function extractParts($stream, int $seq, $struct, string $partPrefix): array
    {
        $result = ['text' => '', 'html' => '', 'attachments' => []];
        $n = 0;
        foreach ($struct->parts as $part) {
            $n++;
            $section = $partPrefix === '' ? (string) $n : $partPrefix . '.' . $n;
            if (!empty($part->parts)) {
                $sub = $this->extractParts($stream, $seq, $part, $section);
                $result['text'] .= $sub['text'];
                $result['html'] .= $sub['html'];
                $result['attachments'] = array_merge($result['attachments'], $sub['attachments']);
                continue;
            }
            $type = $this->mimePrimary($part->type ?? 0);
            $subtype = strtolower((string) ($part->subtype ?? ''));
            $encoding = $part->encoding ?? 0;

            // Attachment: has a filename or disposition attachment.
            $filename = '';
            if (isset($part->dparameters)) {
                foreach ($part->dparameters as $dp) {
                    if (strtolower($dp->attribute ?? '') === 'filename') {
                        $filename = $dp->value ?? '';
                    }
                }
            }
            if ($filename === '' && isset($part->parameters)) {
                foreach ($part->parameters as $p) {
                    if (strtolower($p->attribute ?? '') === 'name') {
                        $filename = $p->value ?? '';
                    }
                }
            }
            $isAttachment = $filename !== '' || strtolower($part->disposition ?? '') === 'attachment';

            $raw = @imap_fetchbody($stream, $seq, $section, FT_UID);
            if ($raw === false) {
                continue;
            }
            $decoded = $this->decodeTransfer((int) $encoding, $raw);

            if ($isAttachment) {
                $result['attachments'][] = [
                    'name' => $this->decodeMime($filename !== '' ? $filename : 'attachment'),
                    'type' => $type . '/' . $subtype,
                    'size' => $part->bytes ?? strlen($decoded),
                    'section' => $section,
                ];
            } elseif ($type === 'text' && $subtype === 'plain') {
                $result['text'] .= $decoded;
            } elseif ($type === 'text' && $subtype === 'html') {
                $result['html'] .= $decoded;
            } elseif ($type === 'text') {
                // text/* fallback
                $result['text'] .= $decoded;
            }
        }

        return $result;
    }

    private function decodeTransfer(int $encoding, string $data): string
    {
        switch ($encoding) {
            case 1: // 8bit
            case 2: // binary
                return $data;
            case 3: // base64
                $d = base64_decode($data, true);
                return $d === false ? $data : $d;
            case 4: // quoted-printable
                return quoted_printable_decode($data);
            case 0: // 7bit
            default:
                return $data;
        }
    }

    private function decodeMime(string $s): string
    {
        $s = str_replace("=\r\n", '', $s);
        $words = @imap_mime_header_decode($s);
        if (!is_array($words)) {
            // Fallback RFC 2047 regex decode.
            return preg_replace_callback(
                '/=\?([^?]+)\?([BQ])\?([^?]*)\?=/i',
                function ($m) {
                    $charset = strtoupper($m[1]);
                    $data = $m[3];
                    if (strtoupper($m[2]) === 'B') {
                        $d = base64_decode($data, true);
                        if ($d === false) {
                            return $m[0];
                        }
                    } else {
                        $d = quoted_printable_decode(str_replace('_', ' ', $data));
                    }
                    return @iconv($charset, 'UTF-8//IGNORE', $d) ?: $d;
                },
                (string) $s
            );
        }
        $out = '';
        foreach ($words as $w) {
            $out .= $w->text;
        }
        return $out;
    }

    private function decodeHeader(string $s): string
    {
        $decoded = $this->decodeMime($s);
        // Strip the personal-name quoting noise but keep the address.
        return trim($decoded);
    }

    private function decodeAddress(string $s): string
    {
        return $this->decodeHeader($s);
    }

    // ------------------------------------------------------------------ SMTP

    /**
     * Send a message as a given mailbox using Dovecot master-user auth (SSO).
     * @throws \RuntimeException
     */
    public function sendAsUser(string $email, string $from, array $to, string $subject, string $body, array $cc = [], array $bcc = [], array $attachments = []): void
    {
        $master = self::masterPassword();
        if ($master === '') {
            throw new \RuntimeException('Webmail single sign-on is not configured.');
        }

        $this->send(self::masterUsername($email), $master, $from, $to, $subject, $body, $cc, $bcc, $attachments);
    }

    /**
     * Send a message via SMTP AUTH PLAIN to the local submission port.
     * $attachments: list of [name, type, bytes]; total capped at 10 MB.
     * @throws \RuntimeException
     */
    public function send(string $username, string $password, string $from, array $to, string $subject, string $body, array $cc = [], array $bcc = [], array $attachments = []): void
    {
        $clean = function (array $list): array {
            $out = [];
            foreach ($list as $addr) {
                $addr = trim((string) $addr);
                if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
                    $out[] = $addr;
                }
            }
            return array_values(array_unique($out));
        };
        $to = $clean($to);
        $cc = $clean($cc);
        $bcc = $clean($bcc);
        if ($to === []) {
            throw new \RuntimeException('No recipients.');
        }
        if (trim($from) === '') {
            $from = $username;
        }

        // Normalize attachments: sanitize names, validate types, cap total size.
        $files = [];
        $totalBytes = 0;
        foreach ($attachments as $a) {
            $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) ($a['name'] ?? 'attachment')));
            $name = substr(trim($name, '._'), 0, 100);
            if ($name === '') {
                $name = 'attachment';
            }
            $type = (string) ($a['type'] ?? '');
            if (!preg_match('#^[\w.+-]+/[\w.+-]+$#', $type)) {
                $type = 'application/octet-stream';
            }
            $bytes = (string) ($a['bytes'] ?? '');
            $totalBytes += strlen($bytes);
            $files[] = ['name' => $name, 'type' => $type, 'bytes' => $bytes];
        }
        if (count($files) > 5) {
            throw new \RuntimeException('Too many attachments (max 5).');
        }
        if ($totalBytes > 10 * 1024 * 1024) {
            throw new \RuntimeException('Attachments too large (max 10 MB total).');
        }

        // Local submission uses a self-signed cert; trust the loopback only.
        $tlsContext = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);

        $fp = @stream_socket_client(
            'tcp://' . self::SMTP_HOST . ':' . self::SMTP_PORT,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $tlsContext
        );
        if ($fp === false) {
            throw new \RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                return null; // EOF
            }
            return trim($line);
        };
        // Read a full (possibly multiline) reply: lines are "code-" (continuation)
        // until the final "code text" line. On EOF returns [0, ''].
        $reply = function () use ($fp, $read) {
            $first = $read();
            if ($first === null) {
                return [0, ''];
            }
            $code = (int) substr($first, 0, 3);
            $last = $first;
            while (strlen($first) >= 4 && substr($first, 3, 1) === '-') {
                $next = $read();
                if ($next === null) {
                    break;
                }
                $last = $next;
                $first = $next;
            }
            return [$code, $last];
        };
        $cmd = function (string $c, array $expect = [250, 235, 334, 354]) use ($fp, $reply) {
            fwrite($fp, $c . "\r\n");
            [$code, $line] = $reply();
            if (!in_array($code, $expect, true)) {
                throw new \RuntimeException("SMTP error ($code) on [$c]: $line");
            }
            return [$code, $line];
        };

        // Consume the server's initial greeting (220 ...) before EHLO.
        [$greeting] = $reply();
        if ($greeting !== 220) {
            throw new \RuntimeException("SMTP unexpected greeting ($greeting)");
        }

        $cmd('EHLO ' . self::SMTP_HOST, [250]);

        // Postfix submission (587) mandates STARTTLS (smtpd_tls_security_level=encrypt).
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new \RuntimeException('STARTTLS negotiation failed.');
        }
        // Re-EHLO over the now-encrypted channel.
        $cmd('EHLO ' . self::SMTP_HOST, [250]);

        // Auth PLAIN (RFC 4616): \0username\0password, base64.
        $auth = base64_encode("\0" . $username . "\0" . $password);
        $cmd('AUTH PLAIN', [334]);
        $cmd($auth, [235]);
        $cmd('MAIL FROM:<' . $from . '>');
        $recipients = array_values(array_unique(array_merge($to, $cc, $bcc)));
        foreach ($recipients as $rcpt) {
            $cmd('RCPT TO:<' . $rcpt . '>');
        }
        $cmd('DATA', [354]);

        $subject = str_replace(["\r", "\n"], '', $subject);
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\n", "\r\n", $body);

        $head = ''
            . 'From: ' . $from . "\r\n"
            . 'To: ' . implode(', ', $to) . "\r\n"
            . ($cc !== [] ? 'Cc: ' . implode(', ', $cc) . "\r\n" : '')
            . 'Subject: ' . $subject . "\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . 'MIME-Version: 1.0' . "\r\n";
        if ($files === []) {
            $msg = $head
                . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                . 'Content-Transfer-Encoding: 8bit' . "\r\n"
                . "\r\n"
                . $body . "\r\n";
        } else {
            $boundary = 'cwhpanel-' . bin2hex(random_bytes(12));
            $msg = $head
                . 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n"
                . "\r\n"
                . '--' . $boundary . "\r\n"
                . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                . 'Content-Transfer-Encoding: 8bit' . "\r\n"
                . "\r\n"
                . $body . "\r\n";
            foreach ($files as $f) {
                $msg .= '--' . $boundary . "\r\n"
                    . 'Content-Type: ' . $f['type'] . '; name="' . $f['name'] . '"' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n"
                    . 'Content-Disposition: attachment; filename="' . $f['name'] . '"' . "\r\n"
                    . "\r\n"
                    . chunk_split(base64_encode($f['bytes']), 76, "\r\n");
            }
            $msg .= '--' . $boundary . "--\r\n";
        }
        // SMTP dot-stuffing: no line of the payload may start with '.'.
        $msg = preg_replace('/^\./m', '..', $msg) . ".\r\n";

        fwrite($fp, $msg);
        // The server replies to the "." terminator with the final DATA result
        // (250 queued, or 451/550 on rejection — e.g. the mail-policy rate
        // limiter or a SpamAssassin milter reject). Read it so a rate-limited or
        // rejected send is surfaced to the caller, not swallowed. Note the
        // mail-policy service defers (DEFER_IF_REJECT -> 451), which Postfix may
        // answer and then close; handle both the explicit 451 and an EOF.
        [$dataCode, $dataLine] = $reply();
        try {
            $cmd('QUIT', [221, 250]);
        } catch (\RuntimeException $ignored) {
            // QUIT may return 221 or the socket may already be closing; ignore.
        }
        fclose($fp);

        if ($dataCode === 0 && $dataLine === '') {
            // Clean EOF with no DATA reply: Postfix accepted-then-closed, or the
            // rate-limit defer answered and dropped the connection. Treat a
            // deferred (queued) message as success; the counter already logged it.
            return;
        }
        if ($dataCode !== 250) {
            throw new \RuntimeException("Message not accepted by server ($dataCode): $dataLine");
        }
    }
}