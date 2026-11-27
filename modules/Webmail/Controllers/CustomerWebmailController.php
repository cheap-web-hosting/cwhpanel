<?php
declare(strict_types=1);
namespace Modules\Webmail\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Webmail\Services\WebmailService;

class CustomerWebmailController
{
    private function accountId(): ?int
    {
        $user = app('auth')->user();
        if (!$user) {
            return null;
        }
        $row = app('db')->selectOne('SELECT `id` FROM `accounts` WHERE `user_id`=?', [$user->id]);
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Mailboxes owned by the current customer's account.
     * @return array<int, array<string,mixed>>
     */
    private function myMailboxes(): array
    {
        $accountId = $this->accountId();
        if ($accountId === null) {
            return [];
        }
        return app('db')->select(
            "SELECT m.*, d.domain FROM `mailboxes` m
             JOIN `mail_domains` d ON d.id = m.domain_id
             WHERE m.account_id = ? AND m.status = 'active'
             ORDER BY m.email",
            [$accountId]
        );
    }

    /**
     * Verify a mailbox belongs to the customer. Returns the row or null.
     */
    private function ownedMailbox(string $email): ?array
    {
        $accountId = $this->accountId();
        $row = app('db')->selectOne(
            "SELECT m.*, d.domain FROM `mailboxes` m
             JOIN `mail_domains` d ON d.id = m.domain_id
             WHERE m.email = ? AND m.account_id = ? AND m.status = 'active'",
            [$email, $accountId]
        );
        return $row ?: null;
    }

    private function sessionState(): ?array
    {
        $s = app('session')->get(WebmailService::SESSION_KEY);
        if (!is_array($s) || empty($s['email'])) {
            return null;
        }
        // Ensure the mailbox still belongs to this customer.
        if ($this->ownedMailbox($s['email']) === null) {
            app('session')->forget(WebmailService::SESSION_KEY);
            return null;
        }
        return $s;
    }

    private function forget(): void
    {
        app('session')->forget(WebmailService::SESSION_KEY);
    }

    /**
     * Open an IMAP stream for the current webmail session, using Dovecot
     * master-user auth (SSO) when the session was opened that way, otherwise
     * the mailbox password the user supplied.
     */
    private function openStream(array $state)
    {
        $svc = app(WebmailService::class);
        if (!empty($state['master'])) {
            return $svc->openAsUser($state['email']);
        }
        return $svc->open($state['email'], (string) ($state['password'] ?? ''));
    }

    /**
     * Send mail for the current webmail session (master auth or password).
     */
    private function sendMail(array $state, string $from, array $to, string $subject, string $body, array $cc = [], array $bcc = [], array $attachments = []): void
    {
        $svc = app(WebmailService::class);
        if (!empty($state['master'])) {
            $svc->sendAsUser($state['email'], $from, $to, $subject, $body, $cc, $bcc, $attachments);
        } else {
            $svc->send($state['email'], (string) ($state['password'] ?? ''), $from, $to, $subject, $body, $cc, $bcc, $attachments);
        }
    }

    // ---------------------------------------------------------------- routes

    public function index(): Response
    {
        if ($this->sessionState() !== null) {
            return Response::redirect('/panel/webmail/inbox');
        }
        $mailboxes = $this->myMailboxes();
        $content = view('customer/webmail/login', [
            'title' => 'Webmail',
            'mailboxes' => $mailboxes,
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    /**
     * Single sign-on: open a mailbox using Dovecot master-user auth, without
     * the customer supplying their mailbox password.
     */
    public function openMailbox(int $id): Response
    {
        $accountId = $this->accountId();
        if ($accountId === null) {
            return Response::redirect('/panel/login');
        }

        $mailbox = app('db')->selectOne(
            "SELECT m.*, d.domain FROM `mailboxes` m
             JOIN `mail_domains` d ON d.id = m.domain_id
             WHERE m.id = ? AND m.account_id = ? AND m.status = 'active'",
            [$id, $accountId]
        );

        if ($mailbox === null) {
            app('session')->flash('error', 'That mailbox is not on your account.');
            return Response::redirect('/panel/email');
        }

        // Validate master auth works before storing the session.
        try {
            $svc = app(WebmailService::class);
            $stream = $svc->openAsUser($mailbox['email']);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', 'Could not open mailbox: ' . $e->getMessage());
            return Response::redirect('/panel/email');
        }

        app('session')->put(WebmailService::SESSION_KEY, [
            'email' => $mailbox['email'],
            'master' => true,
        ]);

        return Response::redirect('/panel/webmail/inbox');
    }

    /**
     * Single sign-on into the bundled Roundcube ("advanced webmail").
     * Issues a short-lived HMAC token and hands off to Roundcube, which
     * validates it and logs the mailbox in via Dovecot master-user auth.
     */
    public function roundcube(int $id): Response
    {
        $accountId = $this->accountId();
        if ($accountId === null) {
            return Response::redirect('/panel/login');
        }
        $mailbox = app('db')->selectOne(
            "SELECT m.* FROM `mailboxes` m
             WHERE m.id = ? AND m.account_id = ? AND m.status = 'active'",
            [$id, $accountId]
        );
        if ($mailbox === null) {
            app('session')->flash('error', 'That mailbox is not on your account.');
            return Response::redirect('/panel/webmail');
        }
        try {
            $token = \Modules\Webmail\Services\RoundcubeSso::issue($mailbox['email']);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail');
        }

        // Hand the SSO token to Roundcube via an HttpOnly SameSite cookie scoped
        // to /roundcube — never in the URL. A URL token lands in nginx access
        // logs, browser history, and Referer headers to third-party resources;
        // a cookie side-steps all three.
        $secure = !empty($_SERVER['HTTPS']) || str_starts_with((string) config('app.url'), 'https://');
        setcookie('cwhpanel_sso_token', $token, [
            'expires' => time() + \Modules\Webmail\Services\RoundcubeSso::TTL,
            'path' => '/roundcube',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return Response::redirect('/roundcube/');
    }

    public function login(Request $request): Response
    {
        try {
            $data = $request->validate(['email' => 'required', 'password' => 'required']);
        } catch (\App\Foundation\ValidationException $e) {
            app('session')->flash('error', 'Email and password are required.');
            return Response::redirect('/panel/webmail');
        }
        $email = strtolower(trim($data['email']));
        $password = (string) $data['password'];

        $mailbox = $this->ownedMailbox($email);
        if ($mailbox === null) {
            app('session')->flash('error', 'That mailbox is not on your account.');
            return Response::redirect('/panel/webmail');
        }

        // Authenticate against Dovecot before storing anything in session.
        try {
            $svc = app(WebmailService::class);
            $stream = $svc->open($email, $password);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', 'Login failed — check the mailbox password.');
            return Response::redirect('/panel/webmail');
        }

        // Rotate the session id on authentication so an attacker-preset cookie
        // cannot become the victim's authenticated webmail session.
        app('session')->regenerate();

        app('session')->put(WebmailService::SESSION_KEY, [
            'email' => $email,
            'password' => $password,
        ]);

        return Response::redirect('/panel/webmail/inbox');
    }

    public function logout(): Response
    {
        $this->forget();
        app('session')->flash('success', 'Logged out of webmail.');
        return Response::redirect('/panel/webmail');
    }

    public function inbox(Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        return $this->renderFolder('INBOX', $request);
    }

    public function folder(Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        $folder = (string) $request->query('name', 'INBOX');
        return $this->renderFolder($folder, $request);
    }

    private function renderFolder(string $folder, Request $request): Response
    {
        $state = $this->sessionState();
        $email = $state['email'];
        $page = max(1, (int) $request->query('page', 1));
        $q = trim((string) $request->query('q', ''));

        try {
            $svc = app(WebmailService::class);
            $stream = $this->openStream($state);
            $folders = $svc->folders($stream);
            $listing = $q !== ''
                ? $svc->search($stream, $folder, $q, $page, 25)
                : $svc->headers($stream, $folder, $page, 25);
            $trash = $svc->trashFolder($stream);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            $this->forget();
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail');
        }

        $content = view('customer/webmail/inbox', [
            'title' => 'Webmail — ' . $folder,
            'email' => $email,
            'folder' => $folder,
            'folders' => $folders,
            'listing' => $listing,
            'q' => $q,
            'trash' => $trash,
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function read(int $uid, Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        $folder = (string) $request->query('folder', 'INBOX');

        try {
            $svc = app(WebmailService::class);
            $stream = $this->openStream($state);
            $svc->selectFolder($stream, $folder);
            $message = $svc->message($stream, $uid);
            $folders = $svc->folders($stream);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            $this->forget();
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail');
        }

        $content = view('customer/webmail/read', [
            'title' => 'Webmail',
            'email' => $state['email'],
            'message' => $message,
            'folders' => $folders,
            'folder' => $folder,
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    /**
     * Download one attachment by its index in the message's attachment list.
     */
    public function downloadAttachment(int $uid, int $index, Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        $folder = (string) $request->query('folder', 'INBOX');

        try {
            $svc = app(WebmailService::class);
            $stream = $this->openStream($state);
            $svc->selectFolder($stream, $folder);
            $message = $svc->message($stream, $uid);
            $list = $message['attachments'] ?? [];
            if (!isset($list[$index]['section'])) {
                @imap_close($stream);
                throw new \RuntimeException('Attachment not found.');
            }
            $file = $svc->attachment($stream, $uid, (string) $list[$index]['section']);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail/read/' . $uid);
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($file['name']));
        if ($safe === '' || $safe === '.' || $safe === '..') {
            $safe = 'attachment';
        }
        return (new Response($file['bytes']))
            ->header('Content-Type', $file['type'] !== '' ? $file['type'] : 'application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $safe) . '"')
            ->header('Content-Length', (string) strlen($file['bytes']));
    }

    /**
     * Move a message to another folder.
     */
    public function move(Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        $uid = (int) $request->input('uid', 0);
        $from = (string) $request->input('folder', 'INBOX');
        $to = (string) $request->input('to_folder', '');
        try {
            $stream = $this->openStream($state);
            app(WebmailService::class)->move($stream, $uid, $from, $to);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            $this->forget();
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail');
        }
        app('session')->flash('success', 'Message moved to ' . $to . '.');
        return Response::redirect('/panel/webmail/folder?name=' . urlencode($from));
    }

    /**
     * Permanently delete everything in the Trash folder.
     */
    public function emptyTrash(Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        try {
            $svc = app(WebmailService::class);
            $stream = $this->openStream($state);
            $trash = $svc->trashFolder($stream);
            if ($trash === null) {
                @imap_close($stream);
                throw new \RuntimeException('No Trash folder on this account.');
            }
            $n = $svc->emptyFolder($stream, $trash);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail/inbox');
        }
        app('session')->flash('success', $n === 0 ? 'Trash is already empty.' : ('Emptied Trash (' . $n . ' message' . ($n === 1 ? '' : 's') . ').'));
        return Response::redirect('/panel/webmail/inbox');
    }

    public function compose(Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        $draft = app('session')->pull('webmail_failed_draft', []);
        if (($draft['email'] ?? null) !== $state['email']) $draft = [];
        $content = view('customer/webmail/compose', [
            'title' => 'Compose',
            'email' => $state['email'],
            'to' => $draft['to'] ?? (string) $request->query('to', ''),
            'cc' => $draft['cc'] ?? '',
            'bcc' => $draft['bcc'] ?? '',
            'subject' => $draft['subject'] ?? (string) $request->query('subject', ''),
            'body' => $draft['body'] ?? '',
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function reply(int $uid, Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        try {
            $svc = app(WebmailService::class);
            $stream = $this->openStream($state);
            $message = $svc->message($stream, $uid);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            $this->forget();
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail');
        }

        $from = $this->extractEmail($message['from']);
        $content = view('customer/webmail/compose', [
            'title' => 'Reply',
            'email' => $state['email'],
            'to' => $from,
            'subject' => 'Re: ' . ltrim((string) $message['subject'], 'Re: '),
        ], 'layouts/customer');
        return new Response((string) $content);
    }

    public function send(Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        try {
            $data = $request->validate(['to' => 'required', 'subject' => 'required', 'body' => 'required']);
        } catch (\App\Foundation\ValidationException $e) {
            app('session')->flash('webmail_failed_draft', ['email' => $state['email']] + $request->only(['to', 'cc', 'bcc', 'subject', 'body']));
            app('session')->flash('error', 'To, subject and body are required.');
            return Response::redirect('/panel/webmail/compose');
        }

        $split = function ($v): array {
            return array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', (string) $v))));
        };
        $to = $split($data['to']);
        $cc = $split($request->input('cc', ''));
        $bcc = $split($request->input('bcc', ''));
        $attachments = $this->uploadedAttachments($request);
        try {
            $this->sendMail($state, $state['email'], $to, (string) $data['subject'], (string) $data['body'], $cc, $bcc, $attachments);
        } catch (\RuntimeException $e) {
            app('session')->flash('webmail_failed_draft', ['email' => $state['email']] + $request->only(['to', 'cc', 'bcc', 'subject', 'body']));
            app('session')->flash('error', 'Could not send: ' . $e->getMessage());
            return Response::redirect('/panel/webmail/compose');
        }

        app('session')->flash('success', 'Message sent.');
        return Response::redirect('/panel/webmail/inbox');
    }

    /**
     * Normalize the `attachments[]` multi-file upload into [name, type, bytes]
     * rows. Skips empty slots; hard-caps each file at 8 MB.
     */
    private function uploadedAttachments(Request $request): array
    {
        $raw = $request->file('attachments');
        if (!is_array($raw) || !isset($raw['name'])) {
            return [];
        }
        $multi = is_array($raw['name']);
        $names = $multi ? $raw['name'] : [$raw['name']];
        $types = $multi ? ($raw['type'] ?? []) : [$raw['type'] ?? ''];
        $tmps = $multi ? ($raw['tmp_name'] ?? []) : [$raw['tmp_name'] ?? ''];
        $errs = $multi ? ($raw['error'] ?? []) : [$raw['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = $multi ? ($raw['size'] ?? []) : [$raw['size'] ?? 0];
        $out = [];
        $n = count($names);
        for ($i = 0; $i < $n; $i++) {
            $err = (int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Upload failed (code ' . $err . ').');
            }
            if ((int) ($sizes[$i] ?? 0) > 8 * 1024 * 1024) {
                throw new \RuntimeException('Attachment "' . (string) $names[$i] . '" exceeds 8 MB.');
            }
            $tmp = (string) ($tmps[$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }
            $bytes = @file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read upload "' . (string) $names[$i] . '".');
            }
            $out[] = ['name' => (string) $names[$i], 'type' => (string) ($types[$i] ?? ''), 'bytes' => $bytes];
        }
        return $out;
    }

    public function delete(Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        $uid = (int) $request->input('uid', 0);
        $folder = (string) $request->input('folder', 'INBOX');
        try {
            $stream = $this->openStream($state);
            $svc = app(WebmailService::class);
            $svc->selectFolder($stream, $folder);
            @imap_delete($stream, (string) $uid, FT_UID);
            @imap_expunge($stream);
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            $this->forget();
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail');
        }
        app('session')->flash('success', 'Message deleted.');
        return Response::redirect('/panel/webmail/folder?name=' . urlencode($folder));
    }

    public function flag(int $uid, Request $request): Response
    {
        $state = $this->sessionState();
        if ($state === null) {
            return Response::redirect('/panel/webmail');
        }
        $flag = (string) $request->input('flag', '\\Seen');
        // Allowlist: never pass an arbitrary atom into the IMAP client.
        $allowedFlags = ['\\Seen', '\\Answered', '\\Flagged', '\\Deleted', '\\Draft'];
        if (!in_array($flag, $allowedFlags, true)) { $flag = '\\Seen'; }
        $set = (bool) $request->input('set', 1);
        try {
            $stream = $this->openStream($state);
            @imap_setflag_full($stream, (string) $uid, $flag, ST_UID);
            if (!$set) {
                @imap_clearflag_full($stream, (string) $uid, $flag, ST_UID);
            }
            @imap_close($stream);
        } catch (\RuntimeException $e) {
            $this->forget();
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/webmail');
        }
        return Response::redirectBack('/panel/webmail/inbox');
    }

    private function extractEmail(string $header): string
    {
        if (preg_match('/<([^>]+)>/', $header, $m)) {
            return trim($m[1]);
        }
        $parts = preg_split('/\s+/', trim($header));
        return end($parts) ?: $header;
    }
}
