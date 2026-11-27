<?php

declare(strict_types=1);

namespace App\Foundation;

class Autoloader
{
    private static array $prefixes = [];

    public static function register(): void
    {
        self::add('App\\', BASE_PATH . '/app');
        self::add('Modules\\', BASE_PATH . '/modules');

        spl_autoload_register([self::class, 'load'], true, true);
    }

    public static function add(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        self::$prefixes[$prefix] = $baseDir;
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}
