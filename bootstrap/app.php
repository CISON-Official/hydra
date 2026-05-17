<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\LogAndMonitorRequests;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\APIMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        api: __DIR__ . '/../routes/api.php',
        health: '/up'
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Prepend your monitoring middleware to the very start of the request pipeline
        $middleware->prepend([
            LogAndMonitorRequests::class,
            // APIMiddleware::class
        ]);

        // Append security headers specifically to your API group
        $middleware->api(append: [
            SecurityHeadersMiddleware::class,
        ]);

        // Register your 'role' alias for routes/api.php
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'api.key' => APIMiddleware::class,
        ]);

        // FIX: If a web browser hits a protected API route, redirect it to a clean path string
        $middleware->redirectGuestsTo(fn() => '/login');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // FIX: Force ALL unauthenticated API requests to return pure JSON instead of a web redirect
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'Unauthenticated.',
                    'message' => 'Please provide a valid authentication token.'
                ], 401);
            }
        });
    })->create();
