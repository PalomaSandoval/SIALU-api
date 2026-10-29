<?php

namespace App\Http\Middleware;

use App\Services\AuthenticationService;
use App\Services\SessionManager;
use App\Support\Logger;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class AuthenticateSession
{
    public function __construct(private AuthenticationService $auth, private SessionManager $session, private Logger $logger) {}

    public function handle(Request $request, Closure $next)
    {
        try {
            $valid = $this->auth->validateSession();
        } catch (Throwable $exception) {
            $this->logger->security('Error al validar sesión.', ['exception' => get_class($exception)], 'error');
            $this->session->destroy();
            $valid = false;
        }
        abort_unless($valid, 401, 'Su sesión ha expirado o no es válida. Inicie sesión nuevamente.');

        return $next($request);
    }
}
