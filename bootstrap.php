<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Routing\Router;
use Meulah\Support\Environment;

Environment::load(__DIR__ . '/.env');

$config = require __DIR__ . '/config/app.php';

error_reporting(E_ALL);
ini_set('display_errors', $config['debug'] ? '1' : '0');

return new Application(new Router(), $config['debug']);

