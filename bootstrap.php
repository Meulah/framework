<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Config\Repository;
use Meulah\Exception\ExceptionHandler;
use Meulah\Log\ErrorLogLogger;
use Meulah\Routing\Router;
use Meulah\Support\Environment;

Environment::load(__DIR__ . '/.env');

$config = Repository::load(__DIR__ . '/config');
$debug = $config->bool('app.debug');

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

$app = new Application(
    new Router(),
    $config,
    new ExceptionHandler($debug, new ErrorLogLogger()),
);

return $app;
