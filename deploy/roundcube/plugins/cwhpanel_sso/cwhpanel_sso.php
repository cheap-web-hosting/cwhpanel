<?php
/**
 * Roundcube plugin: cwhpanel single sign-on.
 *
 * Lets the cwhpanel control panel log a customer straight into Roundcube as
 * one of their own mailboxes without the mailbox password (Dovecot master
 * user, same identity path as the panel's built-in webmail).
 *
 *  Flow: the panel hands the SSO token to the browser as an HttpOnly SameSite
 *  cookie scoped to /roundcube, then redirects to /roundcube/ (no URL token,
 *  so it never lands in access logs, browser history, or Referer headers).
 *  On `startup` the plugin routes the request into the normal login action;
 *  on `authenticate` (core's documented SSO extension point) it validates the
 *  cookie token and supplies master-user credentials with valid=true, so
 *  CSRF/request-token checks that only make sense for password-form logins
 *  are bypassed while IMAP auth, session setup, RC database user autocreation
 *  and all logging run through unmodified core code. The cookie is consumed
 *  (expired) immediately after a successful SSO authenticate.
 *
 *  Token format: <b64url(email)>.<expiry-unix>.<hmac-hex>
 *    hmac = HMAC-SHA256(email . '|' . expiry, cwhpanel_sso_key)
 *  Tokens expire after 60 seconds. The mailbox address inside is validated
 *  as an email; unknown addresses simply fail IMAP auth like a bad password.
 *
 * License: GPL-3.0-or-later (Roundcube plugin exception applies).
 */
class cwhpanel_sso extends rcube_plugin
{
    public $task = 'login|mail';

    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('user_create', [$this, 'user_create']);
    }

    public function startup($args)
    {
        if (trim((string) ($_COOKIE['cwhpanel_sso_token'] ?? '')) !== '' && empty($_SESSION['user_id'])) {
            $args['task'] = 'login';
            $args['action'] = 'login';
        }
        return $args;
    }

    public function authenticate($args)
    {
        $tok = trim((string) ($_COOKIE['cwhpanel_sso_token'] ?? ''));
        if ($tok === '' || !empty($_SESSION['user_id'])) {
            return $args;
        }

        $email = $this->validate($tok);
        if ($email === null) {
            return $args;
        }

        $rc = rcmail::get_instance();
        $pass = (string) $rc->config->get('cwhpanel_master_pass', '');
        if ($pass === '') {
            return $args;
        }

        $args['valid'] = true;
        $args['user'] = $email . $rc->config->get('cwhpanel_master_suffix', '*master@panel');
        $args['pass'] = $pass;
        $args['host'] = $rc->config->get('default_host', '127.0.0.1');
        // SSO lands on a single cookie-carrying GET (the SSO cookie is the
        // credential); the session cookie is planted on the login response
        // instead. Consume the SSO cookie immediately (single-use per client).
        $this->consumeCookie();

        return $args;
    }

    /**
     * First-login provisioning: master-user logins arrive as
     * "<mailbox>*master@panel". Keep that stable string as the Roundcube
     * username (it matches on every login, so no duplicate rows), but set
     * the identity email/name to the plain mailbox — otherwise replies
     * would go out with the master username as From and trip the panel's
     * sender-login enforcement. Password logins never match and pass
     * through untouched.
     */
    public function user_create($args)
    {
        if (preg_match('/^([^@\s]+@[^@\s*]+)\*master@panel$/', (string) ($args['user'] ?? ''), $m)) {
            $args['user_email'] = $m[1];
            $args['user_name'] = $m[1];
        }
        return $args;
    }

    /**
     * Validate an SSO token. Returns the mailbox address or null.
     */
    private function validate($tok)
    {
        $parts = explode('.', $tok, 3);
        if (count($parts) !== 3) {
            return null;
        }
        list($b64, $exp, $sig) = $parts;
        if (!ctype_digit($exp) || (int) $exp < time()) {
            return null;
        }
        $email = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($email === false) {
            return null;
        }
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $key = (string) rcmail::get_instance()->config->get('cwhpanel_sso_key', '');
        if ($key === '') {
            return null;
        }
        $want = hash_hmac('sha256', $email . '|' . $exp, $key);
        if (!hash_equals($want, strtolower($sig))) {
            return null;
        }
        return $email;
    }

    /**
     * Expire the SSO cookie so a captured token cannot be reused for another
     * request (single-use per browser session).
     */
    private function consumeCookie()
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie('cwhpanel_sso_token', '', [
            'expires' => time() - 3600,
            'path' => '/roundcube',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
