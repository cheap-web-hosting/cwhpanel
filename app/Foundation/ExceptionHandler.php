<?php

declare(strict_types=1);

namespace App\Foundation;

use Throwable;

class ExceptionHandler
{
    private Logger $logger;

    private bool $debug;

    public function __construct(Logger $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    public function register(): void
    {
        set_exception_handler([$this, 'handle']);
        set_error_handler([$this, 'handleError']);
    }

    public function handle(Throwable $e): void
    {
        // CLI scripts (migrate/seed/e2e) must never attempt HTTP output:
        // http_response_code() fatals once anything was echoed and masks the
        // real error. Report on STDERR and exit non-zero instead.
        if (PHP_SAPI === 'cli') {
            try {
                $this->logger->error($e->getMessage(), [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } catch (\Throwable $ignored) {
            }
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n");
            exit(1);
        }
        $this->render($e)->send();
    }

    public function render(Throwable $e): Response
    {
        $this->logger->error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Guard against running before the container's `request` binding is
        // registered (e.g. an exception thrown mid-bootstrap). Without this, the
        // renderer itself would crash on app('request') and mask the original error.
        $request = null;
        try {
            $request = app('request');
        } catch (\Throwable $ignored) {
            $request = null;
        }

        if ($request && $request->wantsJson()) {
            return Response::json([
                'error' => $this->debug ? $e->getMessage() : 'Server error.',
            ], $this->statusFor($e));
        }

        // Permission denials from authorize() bounce like the can:
        // middleware (flash + landing), never a bare 500 — direct hits on
        // ungated-but-authorizing actions (e.g. the dashboard) must behave
        // identically to gated routes.
        if ($e instanceof \App\Foundation\Auth\ForbiddenException) {
            try {
                app('session')->flash('error', 'You do not have permission to access this section.');
            } catch (\Throwable $ignored) {
            }

            return Response::redirect('/admin');
        }

        return $this->renderHtml($e);
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // The PHP IMAP extension (c-client) emits a request-shutdown notice when a
        // trusted localhost Dovecot advertises AUTH=PLAIN ("SECURITY PROBLEM:
        // insecure server advertised AUTH=PLAIN" / "Can't do secure authentication
        // with this server"). It is a spurious MITM-warning heuristic for a
        // loopback connection the panel itself configures, not an application
        // error (IMAP read/list still succeed). Suppress only this specific
        // diagnostic so it does not surface as a 500 at shutdown.
        if ($file === 'Unknown' || $file === '') {
            $imap = strpos($message, 'SECURITY PROBLEM') !== false
                || strpos($message, 'Can\'t do secure authentication') !== false
                || strpos($message, 'insecure server advertised') !== false;
            if ($imap) {
                return true;
            }
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    private function renderHtml(Throwable $e): Response
    {
        try {
            $view = app('view');

            $content = $view->make('errors/exception', [
                'title' => 'Error',
                'message' => $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
                'debug' => $this->debug,
                'exception' => $e,
            ], 'layouts.auth');

            $status = $this->statusFor($e);

            return new Response((string) $content, $status);
        } catch (\Throwable $viewError) {
            // Pre-bootstrap failures may have no View binding — if the
            // renderer itself throws, it would mask the original error.
            // Fall back to a plain-text body.
            $message = $this->debug ? $e->getMessage() : 'An unexpected error occurred.';
            return new Response((string) $message . "\n", $this->statusFor($e));
        }
    }

    private function statusFor(Throwable $e): int
    {
        if (method_exists($e, 'getStatusCode')) {
            return $e->getStatusCode();
        }

        return 500;
    }
}
