<?php

namespace App\Http\Controllers;

use App\Exceptions\AuthenticationException;
use App\Http\Requests\LoginRequest;
use App\Services\AuthenticationService;
use App\Services\AuthorizationService;
use App\Services\SessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function csrf(Request $request)
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function login(LoginRequest $request, AuthenticationService $auth, SessionManager $session)
    {
        if ($auth->authenticated() && $auth->validateSession()) {
            return response()->json(['data' => $auth->user()]);
        }
        $key = 'sialu-login:'.hash('sha256', $request->ip().'|'.$request->validated('uid'));
        $enabled = (bool) config('security.login.throttle_enabled', true);
        if ($enabled && RateLimiter::tooManyAttempts($key, (int) config('security.login.max_attempts', 5))) {
            return response()->json(['message' => 'Demasiados intentos. Intente nuevamente más tarde.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        try {
            $user = $auth->login($request->validated('uid'), $request->validated('password'));
        } catch (AuthenticationException $exception) {
            if ($enabled && $exception->reason() !== AuthenticationException::SERVICE_UNAVAILABLE) {
                RateLimiter::hit($key, (int) config('security.login.decay_seconds', 300));
            }
            throw $exception;
        }
        RateLimiter::clear($key);

        return response()->json(['data' => $user->toSessionArray(), 'session' => [
            'id' => $session->sessionRecordId(),
        ]]);
    }

    public function logout(AuthenticationService $auth)
    {
        $auth->logout();

        return response()->noContent();
    }

    public function me(SessionManager $session, AuthorizationService $authorization)
    {
        return response()->json(['data' => $session->user(), 'permissions' => $authorization->permissions()]);
    }
}
