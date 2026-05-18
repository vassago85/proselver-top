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

            // /dealer/* and /oem/* prefixes (with EnsureDealerAccess /
            // EnsureOemAccess middleware) were retired — every modern
            // tenant lives under /customer/* and the OEM-vs-dealer
            // distinction is driven by Company::$type inside those
            // pages, not by a separate route prefix.
            Route::middleware(['web', 'auth', 'customer'])
                ->prefix('customer')
                ->name('customer.')
                ->group(base_path('routes/customer.php'));

            Route::middleware(['web', 'auth', 'driver.access'])
                ->prefix('driver')
                ->name('driver.')
                ->group(base_path('routes/driver.php'));

            Route::middleware(['web', 'auth', 'body_builder'])
                ->prefix('body-builder')
                ->name('body-builder.')
                ->group(base_path('routes/body-builder.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        // Apply to the `web` group so it sits in front of Fortify's
        // guest-scoped password reset routes without needing to fork Fortify.
        // The middleware is a no-op on non-password-reset paths.
        $middleware->appendToGroup('web', \App\Http\Middleware\ThrottlePasswordReset::class);

        // ForceChangePassword middleware temporarily DISABLED (2026-05-05) —
        // the forced-rotation Livewire flow surfaced a stale-SW issue that
        // blocked the driver test. Re-enable once the SW retirement has had
        // time to roll out across all tester devices and the new-user
        // password change has been verified end-to-end.
        // $middleware->appendToGroup('web', \App\Http\Middleware\ForceChangePassword::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'internal' => \App\Http\Middleware\EnsureInternalAccess::class,
            'driver.access' => \App\Http\Middleware\EnsureDriverAccess::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'customer' => \App\Http\Middleware\EnsureCustomerAccess::class,
            'body_builder' => \App\Http\Middleware\EnsureBodyBuilderAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
