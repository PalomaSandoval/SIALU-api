<?php

declare(strict_types=1);

namespace App\Models\Catalogos;

final class Permiso
{
    public function __construct(
        private int $idPermiso,
        private string $clave,
        private string $descripcion,
        private bool $vigente
    ) {}

    public function idPermiso(): int
    {
        return $this->idPermiso;
    }

    public function clave(): string
    {
        return $this->clave;
    }

    public function descripcion(): string
    {
        return $this->descripcion;
    }

    public function vigente(): bool
    {
        return $this->vigente;
    }

    /**
     * @return array{
     *     IdPermiso: int,
     *     Clave: string,
     *     Descripcion: string,
     *     Vigente: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'IdPermiso' => $this->idPermiso,

            'Clave' => $this->clave,

            'Descripcion' => $this->descripcion,

            'Vigente' => $this->vigente,
        ];
    }
}
