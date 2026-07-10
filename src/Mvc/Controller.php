<?php

declare(strict_types=1);

namespace Meulah\Mvc;

use LogicException;
use Meulah\Http\Response;
use Meulah\View\View;

abstract class Controller
{
    public function __construct(private readonly ?View $views = null)
    {
    }

    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        if ($this->views === null) {
            throw new LogicException('No view renderer was provided to the controller.');
        }

        return Response::html($this->views->render($name, $data), $status);
    }
}

