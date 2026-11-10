<?php

use App\Domain\Tasks\Exceptions\TaskStateConflict;
use App\Http\Middleware\EnsureTenant;
use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottlePasswordReset;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [
            EnsureUserIsApproved::class,
            EnsureTenant::class,
            ThrottlePasswordReset::class,
        ]);

        $middleware->api(append: [
            EnsureUserIsApproved::class,
            EnsureTenant::class,
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
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

        // Expected user-level outcomes (409), not failures: keep them out of error logs.
        $exceptions->dontReport([TaskStateConflict::class]);

        // Never reveal model class names or IDs (e.g. "No query results for model [App\Models\Task]").
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return null;
        });
    })->create();
