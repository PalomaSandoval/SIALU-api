<?php

namespace App\Services;

use App\Models\AuthenticatedUser;
use Illuminate\Http\Request;
use RuntimeException;

class SessionManager
{
    public function __construct(private Request $request) {}

    public function authenticate(AuthenticatedUser $user, int $sessionRecordId): void
    {
        if ($sessionRecordId < 1) {
            throw new RuntimeException('El identificador de sesión no es válido.');
        }
        $session = $this->request->session();
        $session->regenerate();
        $session->put('auth', [
            'authenticated' => true,
            'user' => $user->toSessionArray(),
            'session_record_id' => $sessionRecordId,
            'started_at' => time(),
            'last_activity' => time(),
            'regenerated_at' => time(),
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    public function authenticated(): bool
    {
        return $this->request->session()->get('auth.authenticated') === true
            && is_array($this->request->session()->get('auth.user'));
    }

    public function user(): ?array
    {
        return $this->authenticated() ? $this->request->session()->get('auth.user') : null;
    }

    public function userValue(string $key, mixed $default = null): mixed
    {
        return $this->user()[$key] ?? $default;
    }

    public function sessionRecordId(): ?int
    {
        $id = $this->request->session()->get('auth.session_record_id');

        return $this->authenticated() && is_numeric($id) ? (int) $id : null;
    }

    public function inactivitySeconds(): int
    {
        return max(0, time() - (int) $this->request->session()->get('auth.last_activity', time()));
    }

    public function touch(): void
    {
        if (! $this->authenticated()) {
            return;
        }
        $session = $this->request->session();
        $session->put('auth.last_activity', time());
        $interval = (int) config('sialu.regenerate_interval', 300);
        if ($interval > 0 && time() - (int) $session->get('auth.regenerated_at', 0) >= $interval) {
            $session->migrate(true);
            $session->put('auth.regenerated_at', time());
        }
    }

    public function destroy(): void
    {
        $this->request->session()->invalidate();
        $this->request->session()->regenerateToken();
    }
}
