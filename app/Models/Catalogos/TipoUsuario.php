<?php

declare(strict_types=1);

namespace App\Models\Catalogos;

final class TipoUsuario
{
    public function __construct(
        private int $idTipoUsuario,
        private string $descripcion,
        private bool $vigente
    ) {}

    public function idTipoUsuario(): int
    {
        return $this->idTipoUsuario;
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
     *     IdTipoUsuario: int,
     *     Descripcion: string,
     *     Vigente: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'IdTipoUsuario' => $this->idTipoUsuario,

            'Descripcion' => $this->descripcion,

            'Vigente' => $this->vigente,
        ];
    }
}
