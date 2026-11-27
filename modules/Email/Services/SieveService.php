<?php

declare(strict_types=1);

namespace Modules\Email\Services;

/**
 * SieveService — per-mailbox Sieve scripts (filter rules + vacation
 * autoresponder), generated from structured DB rows and pushed to the
 * mailbox home via the agent (mail.sieve.write/delete, sievec-validated).
 *
 * Dovecot runs the script from ~/.dovecot.sieve on LDA/LMTP delivery
 * (see the sieve block in the mail.setup 99-cwhpanel.conf template).
 */
class SieveService
{
    public const FIELDS = ['from', 'to', 'subject', 'body'];

    public const OPERATORS = ['contains', 'is', 'begins', 'ends'];

    public const ACTIONS = ['discard', 'fileinto', 'redirect', 'keep'];

    /**
     * Build a Sieve script from an autoresponder row (or null) and ordered
     * filter rows. All user strings are escaped for Sieve quoted-strings.
     */
    public function buildScript(?array $autoresponder, array $filters): string
    {
        $out = ["require [\"fileinto\", \"vacation\"];", ''];

        $n = 0;
        foreach ($filters as $f) {
            if (!(bool) ($f['enabled'] ?? true)) {
                continue;
            }
            $rule = $this->buildRule($f);
            if ($rule === null) {
                continue;
            }
            $n++;
            $out[] = "# rule {$n}";
            $out[] = $rule;
            $out[] = '';
        }

        if ($autoresponder !== null && (bool) ($autoresponder['enabled'] ?? false)) {
            $subject = $this->escape((string) ($autoresponder['subject'] ?? ''));
            $body = $this->escape((string) ($autoresponder['body'] ?? ''));
            if ($subject !== '' && $body !== '') {
                $out[] = '# autoresponder';
                $out[] = "vacation :days 1 :subject \"{$subject}\" \"{$body}\";";
                $out[] = '';
            }
        }

        return implode("\n", $out);
    }

    /**
     * Current DB state for a mailbox's filters page.
     *
     * @return array{auto:?array, filters:array<int,array>}
     */
    public function forMailbox(int $mailboxId): array
    {
        $auto = app('db')->selectOne(
            'SELECT * FROM `mail_autoresponders` WHERE `mailbox_id` = ?',
            [$mailboxId]
        );
        $filters = app('db')->select(
            'SELECT * FROM `mail_filters` WHERE `mailbox_id` = ? ORDER BY `position`, `id`',
            [$mailboxId]
        );
        return ['auto' => $auto, 'filters' => $filters];
    }

    /**
     * Rebuild + push (or remove) the Sieve script for a mailbox from DB state.
     * Returns 'active' when a script was written, 'removed' when deleted.
     */
    public function sync(int $mailboxId): string
    {
        $mb = app('db')->selectOne(
            'SELECT m.*, a.`username` FROM `mailboxes` m
             JOIN `accounts` a ON a.`id` = m.`account_id`
             WHERE m.`id` = ?',
            [$mailboxId]
        );

        if ($mb === null) {
            throw new \RuntimeException('mailbox not found');
        }

        $local = strtolower(explode('@', (string) $mb['email'], 2)[0] ?? '');
        if ($local === '') {
            throw new \RuntimeException('mailbox has no local part');
        }

        $auto = app('db')->selectOne(
            'SELECT * FROM `mail_autoresponders` WHERE `mailbox_id` = ?',
            [$mailboxId]
        );

        $filters = app('db')->select(
            'SELECT * FROM `mail_filters` WHERE `mailbox_id` = ? AND `enabled` = 1 ORDER BY `position`, `id`',
            [$mailboxId]
        );

        $hasAuto = $auto !== null && (bool) ($auto['enabled'] ?? false)
            && trim((string) ($auto['subject'] ?? '')) !== ''
            && trim((string) ($auto['body'] ?? '')) !== '';

        if (!$hasAuto && $filters === []) {
            app('provisioning')->execute('mail.sieve.delete', [
                'account' => $mb['username'],
                'mailbox' => $local,
            ]);
            return 'removed';
        }

        $script = $this->buildScript($auto ?: null, $filters);

        app('provisioning')->execute('mail.sieve.write', [
            'account' => $mb['username'],
            'mailbox' => $local,
            'contents' => $script,
        ]);

        return 'active';
    }

    public function remove(int $mailboxId): void
    {
        $mb = app('db')->selectOne(
            'SELECT m.`email`, a.`username` FROM `mailboxes` m
             JOIN `accounts` a ON a.`id` = m.`account_id`
             WHERE m.`id` = ?',
            [$mailboxId]
        );

        if ($mb === null) {
            return;
        }

        $local = strtolower(explode('@', (string) $mb['email'], 2)[0] ?? '');
        if ($local === '') {
            return;
        }

        app('provisioning')->execute('mail.sieve.delete', [
            'account' => $mb['username'],
            'mailbox' => $local,
        ]);
    }

    /**
     * Build one `if ... { action; }` rule. Returns null when the row is not
     * usable (unknown field/operator/action or bad target).
     */
    private function buildRule(array $f): ?string
    {
        $field = (string) ($f['field'] ?? '');
        $operator = (string) ($f['operator'] ?? '');
        $value = (string) ($f['value'] ?? '');
        $action = (string) ($f['action'] ?? '');
        $target = (string) ($f['target'] ?? '');

        if (!in_array($field, self::FIELDS, true)
            || !in_array($operator, self::OPERATORS, true)
            || !in_array($action, self::ACTIONS, true)
            || $value === ''
        ) {
            return null;
        }

        $test = $this->buildTest($field, $operator, $value);
        if ($test === null) {
            return null;
        }

        $effect = $this->buildAction($action, $target);
        if ($effect === null) {
            return null;
        }

        return "if {$test} {\n    {$effect}\n}";
    }

    private function buildTest(string $field, string $operator, string $value): ?string
    {
        if ($field === 'body') {
            if ($operator === 'begins' || $operator === 'ends') {
                return null;
            }
            $match = $operator === 'is' ? ':is' : ':contains';
            return "body {$match} \"{$this->escape($value)}\"";
        }

        $header = match ($field) {
            'from' => '"From"',
            'to' => '["To", "Cc"]',
            'subject' => '"Subject"',
            default => null,
        };
        if ($header === null) {
            return null;
        }

        return match ($operator) {
            'contains' => "header :contains {$header} \"{$this->escape($value)}\"",
            'is' => "header :is {$header} \"{$this->escape($value)}\"",
            'begins' => "header :matches {$header} \"{$this->escapeMatch($value)}*\"",
            'ends' => "header :matches {$header} \"*{$this->escapeMatch($value)}\"",
            default => null,
        };
    }

    private function buildAction(string $action, string $target): ?string
    {
        return match ($action) {
            'discard' => 'discard; stop;',
            'keep' => 'keep;',
            'fileinto' => $this->validFolder($target)
                ? "fileinto \"{$this->escape($target)}\"; stop;"
                : null,
            'redirect' => filter_var($target, FILTER_VALIDATE_EMAIL)
                ? "redirect \"{$this->escape($target)}\"; stop;"
                : null,
            default => null,
        };
    }

    private function validFolder(string $folder): bool
    {
        $folder = trim($folder);
        return $folder !== '' && strlen($folder) <= 64
            && !str_contains($folder, '..')
            && (bool) preg_match('#^[A-Za-z0-9._/ \-]+$#', $folder);
    }

    /** Escape a string for a Sieve double-quoted string. */
    private function escape(string $s): string
    {
        // Strip CR/LF: they cannot appear inside a Sieve quoted string and
        // would otherwise be neutralized only by sievec rejecting the script.
        $s = str_replace(["\r", "\n"], ["", ""], $s);
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }

    /** Escape a string for a Sieve :matches pattern (adds wildcard escaping). */
    private function escapeMatch(string $s): string
    {
        return str_replace(['\\', '"', '*', '?'], ['\\\\', '\\"', '\\*', '\\?'], $s);
    }
}
