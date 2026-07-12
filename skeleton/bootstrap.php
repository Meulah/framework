<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Config\Repository;
use Meulah\Container\Container;
use Meulah\Exception\ExceptionHandler;
use Meulah\Log\ErrorLogLogger;
use Meulah\Log\Logger;
use Meulah\Routing\Router;
use Meulah\Support\Environment;
use Meulah\View\View;

$root = __DIR__;

Environment::load($root . '/.env');

$config = Repository::load($root . '/config');
$debug = $config->bool('app.debug');

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

$container = new Container();
$logger = new ErrorLogLogger();
$exceptions = new ExceptionHandler($debug, $logger);

$container->instance(Logger::class, $logger);
$container->singleton(View::class, static fn (): View => new View($root . '/resources/views'));

return new Application(
    new Router($container),
    $config,
    $exceptions,
);
