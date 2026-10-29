<?php

namespace App\Http\Middleware;

use App\Models\UserRole;
use App\Services\SessionManager;
use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function __construct(private SessionManager $session) {}

    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $role = UserRole::normalize((string) $this->session->userValue('TipoUsuario', ''));
        abort_unless($this->session->authenticated() && in_array($role, array_map(UserRole::normalize(...), $roles), true), 403, 'No tiene autorización para acceder a este recurso.');

        return $next($request);
    }
}
