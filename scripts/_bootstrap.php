<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/app/Foundation/Autoloader.php';
require_once BASE_PATH . '/app/helpers.php';

App\Foundation\Autoloader::register();
