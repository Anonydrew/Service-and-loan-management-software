<?php

use App\Http\Middleware\BindTenant;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Support\BusinessRuleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie authentication for the React frontend.
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'tenant' => BindTenant::class,
            'permission' => RequirePermission::class,
        ]);
        // Company context and permission checks must run before route-model
        // binding, so bound models are resolved through the company and branch
        // scopes and a forbidden user learns nothing about record existence.
        $middleware->prependToPriorityList(SubstituteBindings::class, BindTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePermission::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (BusinessRuleException $e) => response()->json(
            ['message' => $e->getMessage(), 'error_code' => $e->errorCode] + $e->context,
            $e->status,
        ));

        $exceptions->render(fn (AuthorizationException $e) => response()->json(
            ['message' => $e->getMessage() ?: 'This action is not authorised.', 'error_code' => 'forbidden'],
            403,
        ));

        // A record in another company or an inaccessible branch is simply "not found".
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Record not found.', 'error_code' => 'not_found'], 404);
            }

            return null;
        });
    })->create();
