<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use Meulah\Routing\Router;

/** @var Router $router */
$router->get('/', HomeController::class, 'home');
