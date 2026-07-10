<?php

declare(strict_types=1);

use Meulah\Http\Request;
$root = dirname(__DIR__);
$autoloader = $root . '/vendor/autoload.php';

if (!is_file($autoloader)) {
    throw new RuntimeException('Composer dependencies are missing. Run composer install.');
}

require_once $autoloader;

/** @var Meulah\Application $app */
$app = require $root . '/bootstrap.php';
$router = $app->router();
require $root . '/routes/web.php';

$app->handle(Request::capture())->send();
