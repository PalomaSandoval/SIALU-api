<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

class Config
{
    public function __construct(private Repository $repository) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($key, $default);
    }

    public function getRequired(string $key): mixed
    {
        $value = $this->get($key);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new RuntimeException("La configuración obligatoria \"{$key}\" no está definida.");
        }

        return $value;
    }
}
