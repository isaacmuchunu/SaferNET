<?php

use App\Http\Middleware\AuditApiMutation;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureInstitutionIsApproved;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'account.active' => EnsureAccountIsActive::class,
            'audit.api' => AuditApiMutation::class,
            'institution.approved' => EnsureInstitutionIsApproved::class,
            'role' => EnsureRole::class,
            'tenant' => ResolveTenantContext::class,
        ]);

        // API callers get a 401, never a redirect; a guest on the portal is sent
        // to the sign-in screen the SPA serves.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/signin');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API has no login page to redirect a guest to: it answers in JSON,
        // including when the caller sends no Accept header of its own.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
