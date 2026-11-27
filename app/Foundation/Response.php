<?php

declare(strict_types=1);

namespace App\Foundation;

class Response
{
    private int $status = 200;

    private array $headers = [];

    private string $body = '';

    private ?string $filePath = null;

    private ?string $fileName = null;

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers);
    }

    public static function json($data, int $status = 200): self
    {
        return (new self(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status))
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * Sanitize a filename for Content-Disposition: collapse anything outside
     * a safe set (kills " and CR/LF breakout attempts), never empty.
     */
    public static function safeFilename(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
        if ($safe === '' || $safe === '.' || $safe === '..') {
            $safe = 'attachment';
        }
        return str_replace('"', '', $safe);
    }

    /**
     * Stream a file as a download without loading it into memory.
     * The path must already be validated by the caller.
     *
     * When the file cannot be opened, a 404 is returned instead of a
     * successful-looking 200 with an empty body (the previous behavior —
     * headers were already emitted by the time fopen failed).
     */
    public static function file(string $path, string $filename, string $contentType = 'application/octet-stream'): self
    {
        if (!is_file($path) || !is_readable($path)) {
            app('logger')->warning('Download requested for unreadable file: ' . $path);
            return new self('The requested file is not available.', 404);
        }

        $resp = new self('', 200);
        $resp->filePath = $path;
        $resp->fileName = $filename;
        $resp->header('Content-Type', $contentType);
        $resp->header('Content-Disposition', 'attachment; filename="' . self::safeFilename($filename) . '"');
        $size = @filesize($path);
        if ($size !== false) {
            $resp->header('Content-Length', (string) $size);
        }
        return $resp;
    }

    public static function redirect(string $path, int $status = 302): self
    {
        return (new self('', $status))->header('Location', $path);
    }

    public static function redirectBack(string $fallback = '/'): self
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer === '') {
            return self::redirect($fallback);
        }
        $scheme = parse_url($referer, PHP_URL_SCHEME);
        $refHost = strtolower((string) parse_url($referer, PHP_URL_HOST));
        // Only bounce to a same-origin referer (or a relative one). A foreign
        // origin falls back — closes the HTTP_REFERER open redirect.
        if ($refHost !== '' && $scheme !== null) {
            $appHost = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
            $reqHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($appHost === '' || ($refHost !== $appHost && $refHost !== $reqHost)) {
                return self::redirect($fallback);
            }
        }
        $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($referer, PHP_URL_QUERY) ?? '');
        return self::redirect($path . ($query !== '' ? '?' . $query : ''));
    }

    public function header(string $name, string $value): self
    {
        // Defense-in-depth against response splitting: no header value may
        // carry CR/LF, wherever it came from.
        $value = str_replace(["\r", "\n"], '', $value);
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        if ($this->filePath !== null) {
            $fh = @fopen($this->filePath, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    echo fread($fh, 8192);
                    flush();
                }
                fclose($fh);
            } else {
                // Race window: file vanished between Response::file() validation
                // and send(). Headers are already out, so the status cannot
                // change — but the failure must at least be visible.
                app('logger')->error('Download stream failed to open at send time: ' . $this->filePath);
            }
            return;
        }

        echo $this->body;
    }
}
