<?php

declare(strict_types=1);

namespace App\Foundation;

class Logger
{
    public const DEBUG = 100;
    public const INFO = 200;
    public const NOTICE = 250;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;

    private string $dir;

    private array $levels = [
        100 => 'DEBUG',
        200 => 'INFO',
        250 => 'NOTICE',
        300 => 'WARNING',
        400 => 'ERROR',
        500 => 'CRITICAL',
    ];

    public function __construct(string $dir)
    {
        $this->dir = $dir;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write(self::ERROR, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write(self::CRITICAL, $message, $context);
    }

    private function write(int $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }

        $line = sprintf(
            "[%s] %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            'panel',
            $this->levels[$level] ?? 'INFO',
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        @file_put_contents($this->dir . '/panel.log', $line, FILE_APPEND | LOCK_EX);
    }
}
