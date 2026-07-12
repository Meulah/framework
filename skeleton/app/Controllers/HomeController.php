<?php

declare(strict_types=1);

namespace App\Controllers;

use Meulah\Http\Response;
use Meulah\Mvc\Controller;

final class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return $this->view('home', ['title' => 'Meulah']);
    }
}
