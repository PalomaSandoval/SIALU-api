<?php

declare(strict_types=1);

namespace App\Models\Catalogos;

/**
 * Modelo de dominio para la relación:
 *
 * catdTiposUsuariosPermisos
 *
 * Representa la asignación de un permiso
 * a un tipo de usuario.
 *
 * Este modelo es inmutable y no contiene
 * lógica de persistencia.
 */
final class TipoUsuarioPermiso
{
    /**
     * Identificador único de la relación.
     */
    private int $idTipoUsuarioPermiso;

    /**
     * Identificador del tipo de usuario.
     */
    private int $idTipoUsuario;

    /**
     * Descripción del tipo de usuario.
     */
    private string $tipoUsuario;

    /**
     * Identificador del permiso.
     */
    private int $idPermiso;

    /**
     * Clave técnica del permiso.
     */
    private string $clavePermiso;

    /**
     * Descripción funcional del permiso.
     */
    private string $descripcionPermiso;

    /**
     * Estado lógico de la relación.
     */
    private bool $vigente;

    /**
     * Constructor.
     */
    public function __construct(
        int $idTipoUsuarioPermiso,
        int $idTipoUsuario,
        string $tipoUsuario,
        int $idPermiso,
        string $clavePermiso,
        string $descripcionPermiso,
        bool $vigente
    ) {
        $this->idTipoUsuarioPermiso =
            $idTipoUsuarioPermiso;

        $this->idTipoUsuario =
            $idTipoUsuario;

        $this->tipoUsuario =
            $tipoUsuario;

        $this->idPermiso =
            $idPermiso;

        $this->clavePermiso =
            $clavePermiso;

        $this->descripcionPermiso =
            $descripcionPermiso;

        $this->vigente =
            $vigente;
    }

    /**
     * Obtiene el identificador único
     * de la relación.
     */
    public function idTipoUsuarioPermiso(): int
    {
        return $this->idTipoUsuarioPermiso;
    }

    /**
     * Obtiene el identificador
     * del tipo de usuario.
     */
    public function idTipoUsuario(): int
    {
        return $this->idTipoUsuario;
    }

    /**
     * Obtiene la descripción
     * del tipo de usuario.
     */
    public function tipoUsuario(): string
    {
        return $this->tipoUsuario;
    }

    /**
     * Obtiene el identificador
     * del permiso.
     */
    public function idPermiso(): int
    {
        return $this->idPermiso;
    }

    /**
     * Obtiene la clave técnica
     * del permiso.
     */
    public function clavePermiso(): string
    {
        return $this->clavePermiso;
    }

    /**
     * Obtiene la descripción
     * funcional del permiso.
     */
    public function descripcionPermiso(): string
    {
        return $this->descripcionPermiso;
    }

    /**
     * Indica si la relación
     * se encuentra vigente.
     */
    public function vigente(): bool
    {
        return $this->vigente;
    }

    /**
     * Indica si la relación
     * se encuentra inactiva.
     */
    public function inactivo(): bool
    {
        return ! $this->vigente;
    }

    /**
     * Devuelve la representación del modelo
     * como arreglo.
     *
     * @return array{
     *     IdTipoUsuarioPermiso: int,
     *     IdTipoUsuario: int,
     *     TipoUsuario: string,
     *     IdPermiso: int,
     *     ClavePermiso: string,
     *     DescripcionPermiso: string,
     *     Vigente: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'IdTipoUsuarioPermiso' => $this->idTipoUsuarioPermiso,

            'IdTipoUsuario' => $this->idTipoUsuario,

            'TipoUsuario' => $this->tipoUsuario,

            'IdPermiso' => $this->idPermiso,

            'ClavePermiso' => $this->clavePermiso,

            'DescripcionPermiso' => $this->descripcionPermiso,

            'Vigente' => $this->vigente,
        ];
    }
}
