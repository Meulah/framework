<?php

declare(strict_types=1);

namespace Meulah\Routing;

use Meulah\Http\Middleware;

interface RouteParameterAwareMiddleware extends Middleware
{
    public function withRouteParameters(RouteParameters $parameters): Middleware;
}
