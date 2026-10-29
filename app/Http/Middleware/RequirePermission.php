<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use App\Support\Logger;
use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function __construct(private AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        try {
            $allowed = $this->authorization->hasAllPermissions($permissions);
        } catch (\Throwable $exception) {
            app(Logger::class)->security('Error al evaluar un permiso. Acceso denegado.', ['exception' => get_class($exception)], 'error');
            $allowed = false;
        }
        abort_unless($allowed, 403, 'No tiene permiso para realizar esta operación.');

        return $next($request);
    }
}
