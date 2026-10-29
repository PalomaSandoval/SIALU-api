<?php

use App\Exceptions\AuthenticationException;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'sialu.auth' => AuthenticateSession::class,
            'sialu.role' => RequireRole::class,
            'sialu.permission' => RequirePermission::class,
        ]);
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $exception): bool {
            return $request->is('api', 'api/*') || $request->expectsJson();
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                $status = match ($exception->reason()) {
                    AuthenticationException::SERVICE_UNAVAILABLE => 503,
                    AuthenticationException::INVALID_CREDENTIALS => 401,
                    default => 403,
                };

                return response()->json(['message' => $exception->userMessage()], $status);
            }
        });
        $exceptions->render(function (InvalidArgumentException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage() ?: 'Solicitud no válida.'], $exception->getStatusCode(), $exception->getHeaders());
            }
        });
        $exceptions->render(function (RuntimeException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No fue posible completar la operación. Intente nuevamente.'], 503);
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/*') && ! $exception instanceof ValidationException) {
                return response()->json(['message' => 'Ocurrió un error al procesar la solicitud.'], 500);
            }
        });
        $exceptions->respond(function (Response $response) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        });
    })->create();
