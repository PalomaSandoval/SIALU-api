<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class Logger
{
    public function log(string $level, string $message, array $context = [], string $channel = 'application'): void
    {
        Log::channel('sialu_'.$channel)->log($level, $message, $this->sanitize($context));
    }

    public function security(string $message, array $context = [], string $level = 'info'): void
    {
        $this->log($level, $message, $context, 'security');
    }

    public function ldap(string $message, array $context = [], string $level = 'info'): void
    {
        $this->log($level, $message, $context, 'ldap');
    }

    public function database(string $message, array $context = [], string $level = 'info'): void
    {
        $this->log($level, $message, $context, 'database');
    }

    public function audit(string $message, array $context = []): void
    {
        $this->log('info', $message, $context, 'audit');
    }

    public function __call(string $level, array $arguments): void
    {
        $this->log($level, $arguments[0], $arguments[1] ?? [], in_array($level, ['error', 'critical', 'emergency']) ? 'error' : 'application');
    }

    private function sanitize(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/password|passwd|secret|token|authorization|cookie|session|app_key/i', (string) $key)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }

        return $context;
    }
}
