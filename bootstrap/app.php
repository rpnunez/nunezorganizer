<?php

use App\Http\Middleware\EnsureTenant;
use App\Http\Middleware\EnsureUserIsApproved;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureUserIsApproved::class,
            EnsureTenant::class,
        ]);

        // Order: approval check -> tenant resolution -> route-model binding, so that
        // bound tenant models are already scoped to the authenticated user's account.
        // (Prepends are applied in call order; EnsureTenant must be registered first.)
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureTenant::class);
        $middleware->prependToPriorityList(before: EnsureTenant::class, prepend: EnsureUserIsApproved::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
