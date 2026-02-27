<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['web', 'auth', 'internal'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware(['web', 'auth', 'dealer'])
                ->prefix('dealer')
                ->name('dealer.')
                ->group(base_path('routes/dealer.php'));

            Route::middleware(['web', 'auth', 'driver.access'])
                ->prefix('driver')
                ->name('driver.')
                ->group(base_path('routes/driver.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'internal' => \App\Http\Middleware\EnsureInternalAccess::class,
            'dealer' => \App\Http\Middleware\EnsureDealerAccess::class,
            'driver.access' => \App\Http\Middleware\EnsureDriverAccess::class,
            'company' => \App\Http\Middleware\EnsureCompanyAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
