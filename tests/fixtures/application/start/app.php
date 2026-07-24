<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Config\Repository;
use Meulah\Routing\Router;

$GLOBALS['meulah_test_application_boots'] = ($GLOBALS['meulah_test_application_boots'] ?? 0) + 1;
$root = dirname(__DIR__);

return new Application(
    new Router(),
    Repository::load($root . '/settings'),
);
