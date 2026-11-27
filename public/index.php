<?php

declare(strict_types=1);

use App\Foundation\Application;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Foundation/Autoloader.php';
require BASE_PATH . '/app/helpers.php';

App\Foundation\Autoloader::register();

$app = new Application(BASE_PATH);

$app->bootstrap();

$response = $app->handle();

$response->send();
