<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Meulah\Config\Repository;
use Meulah\Http\Request;

interface Greeting
{
    public function for(string $name): string;
}

final class FriendlyGreeting implements Greeting
{
    public function for(string $name): string
    {
        return "Hello, {$name}!";
    }
}

final class GreetingController
{
    public function __construct(
        private readonly Greeting $greeting,
        private readonly Repository $config,
    ) {
    }

    public function show(Request $request, string $name): string
    {
        return $this->config->string('app.name') . ': ' . $this->greeting->for($name);
    }
}

final class InvokableGreetingController
{
    public function __construct(private readonly Greeting $greeting)
    {
    }

    public function __invoke(Request $request): string
    {
        return $this->greeting->for($request->string('name'));
    }
}

final class ScalarDependencyController
{
    public function __construct(public readonly string $apiKey)
    {
    }

    public function __invoke(): string
    {
        return $this->apiKey;
    }
}

final class CircularOne
{
    public function __construct(public readonly CircularTwo $two)
    {
    }
}

final class CircularTwo
{
    public function __construct(public readonly CircularOne $one)
    {
    }
}
