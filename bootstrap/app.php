<?php

use App\Exceptions\JetGridSafetyException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // JetGrid has no login route of its own — Filament owns authentication,
        // so guests are sent to the panel rather than to a route that does not
        // exist.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->expectsJson()
            ? null
            : route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * A safety refusal is not a crash. Every JetGrid guard throws a subclass
         * of JetGridSafetyException carrying a message written for the operator,
         * so it is rendered as a 403 with that explanation rather than a stack
         * trace — the reason a write was refused is the single most useful thing
         * this app can tell you.
         */
        $exceptions->render(function (JetGridSafetyException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'refused' => true,
                    'reason' => $e->userMessage(),
                    'detail' => $e->getMessage(),
                ], 403);
            }

            return response()->view('errors.refused', [
                'reason' => $e->userMessage(),
                'detail' => $e->getMessage(),
            ], 403);
        });
    })->create();
