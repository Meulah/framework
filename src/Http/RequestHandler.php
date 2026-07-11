<?php

declare(strict_types=1);

namespace Meulah\Http;

interface RequestHandler
{
    public function handle(Request $request): Response;
}

