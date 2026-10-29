<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Models\Catalogos\TipoUsuarioPermiso;
use App\Repositories\Catalogos\TipoUsuarioPermisoRepository;
use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TipoUsuarioPermisoService
{
    private TipoUsuarioPermisoRepository $repository;

    private Logger $logger;

    private TipoUsuarioService $tipoUsuarioService;

    private PermisoService $permisoService;

    public function __construct(
        TipoUsuarioPermisoRepository $repository,
        TipoUsuarioService $tipoUsuarioService,
        PermisoService $permisoService,
        Logger $logger
    ) {
        $this->repository = $repository;

        $this->tipoUsuarioService = $tipoUsuarioService;

        $this->permisoService = $permisoService;

        $this->logger = $logger;
    }

    /**
     * Obtiene todas las relaciones
     * Tipo de Usuario - Permiso.
     *
     * @return array<int, TipoUsuarioPermiso>
     */
    public function all(): array
    {
        try {
            return $this->repository->all();

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible obtener el catálogo '
                .'de tipos de usuarios y permisos.',
                [
                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar las asignaciones '
                .'de tipos de usuarios y permisos.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca una relación por su ID.
     */
    public function find(
        int $idTipoUsuarioPermiso
    ): ?TipoUsuarioPermiso {
        if ($idTipoUsuarioPermiso < 1) {
            return null;
        }

        try {
            return $this->repository->findById(
                $idTipoUsuarioPermiso
            );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible consultar la asignación '
                .'de tipo de usuario y permiso.',
                [
                    'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar la asignación.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca una relación por ID o lanza
     * excepción cuando no existe.
     */
    public function findOrFail(
        int $idTipoUsuarioPermiso
    ): TipoUsuarioPermiso {
        $relation = $this->find(
            $idTipoUsuarioPermiso
        );

        if ($relation === null) {
            throw new RuntimeException(
                'La asignación solicitada no existe.'
            );
        }

        return $relation;
    }

    /**
     * Registra una nueva relación.
     */
    public function create(
        int $idTipoUsuario,
        int $idPermiso
    ): TipoUsuarioPermiso {
        /*
         * Las validaciones de negocio se realizan
         * antes del try de persistencia para que
         * InvalidArgumentException llegue intacta
         * al Controller.
         */
        $this->validateRelationIds(
            $idTipoUsuario,
            $idPermiso
        );

        $this->validateActiveCatalogs(
            $idTipoUsuario,
            $idPermiso
        );

        $this->ensureRelationIsUnique(
            $idTipoUsuario,
            $idPermiso
        );

        try {
            $idRelacion =
                $this->repository->create(
                    $idTipoUsuario,
                    $idPermiso
                );

            $relation =
                $this->repository->findById(
                    $idRelacion
                );

            if ($relation === null) {
                throw new RuntimeException(
                    'La asignación fue registrada, '
                    .'pero no pudo recuperarse.'
                );
            }

            $this->logger->database(
                'Asignación TipoUsuario-Permiso '
                .'creada correctamente.',
                [
                    'IdTipoUsuarioPermiso' => $relation
                        ->idTipoUsuarioPermiso(),

                    'IdTipoUsuario' => $relation->idTipoUsuario(),

                    'IdPermiso' => $relation->idPermiso(),

                    'Vigente' => $relation->vigente(),
                ]
            );

            return $relation;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible crear la asignación '
                .'TipoUsuario-Permiso.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'IdPermiso' => $idPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible registrar la asignación '
                .'de tipo de usuario y permiso.',
                0,
                $exception
            );
        }
    }

    /**
     * Modifica una relación existente.
     *
     * Vigente NO se modifica aquí.
     */
    public function update(
        int $idTipoUsuarioPermiso,
        int $idTipoUsuario,
        int $idPermiso
    ): TipoUsuarioPermiso {
        if ($idTipoUsuarioPermiso < 1) {
            throw new InvalidArgumentException(
                'IdTipoUsuarioPermiso no válido.'
            );
        }

        /*
        * Primero confirmamos que la relación exista.
        */
        $current = $this->findOrFail(
            $idTipoUsuarioPermiso
        );

        /*
        * Validamos los nuevos IDs.
        */
        $this->validateRelationIds(
            $idTipoUsuario,
            $idPermiso
        );

        /*
        * Si los valores relacionados cambian,
        * los nuevos catálogos deben existir
        * y encontrarse vigentes.
        *
        * Los valores actuales pueden conservarse
        * aunque posteriormente hayan sido
        * desactivados.
        */
        $this->validateActiveCatalogsForUpdate(
            $current,
            $idTipoUsuario,
            $idPermiso
        );

        /*
        * Si no hubo cambios reales,
        * evitamos el UPDATE.
        */
        if (
            $current->idTipoUsuario()
                === $idTipoUsuario
            && $current->idPermiso()
                === $idPermiso
        ) {
            return $current;
        }

        /*
        * La combinación nueva no debe pertenecer
        * a otra relación.
        */
        $this->ensureRelationIsUnique(
            $idTipoUsuario,
            $idPermiso,
            $idTipoUsuarioPermiso
        );

        try {
            $updated =
                $this->repository->update(
                    $idTipoUsuarioPermiso,
                    $idTipoUsuario,
                    $idPermiso
                );

            if (! $updated) {
                throw new RuntimeException(
                    'No se encontró la asignación '
                    .'que se desea modificar.'
                );
            }

            $relation =
                $this->repository->findById(
                    $idTipoUsuarioPermiso
                );

            if ($relation === null) {
                throw new RuntimeException(
                    'La asignación fue actualizada, '
                    .'pero no pudo recuperarse.'
                );
            }

            $this->logger->database(
                'Asignación TipoUsuario-Permiso '
                .'actualizada correctamente.',
                [
                    'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

                    'IdTipoUsuario' => $idTipoUsuario,

                    'IdPermiso' => $idPermiso,
                ]
            );

            return $relation;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible actualizar la asignación '
                .'TipoUsuario-Permiso.',
                [
                    'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

                    'IdTipoUsuario' => $idTipoUsuario,

                    'IdPermiso' => $idPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible modificar la asignación '
                .'de tipo de usuario y permiso.',
                0,
                $exception
            );
        }
    }

    /**
     * Baja lógica.
     *
     * Operación idempotente.
     */
    public function deactivate(
        int $idTipoUsuarioPermiso
    ): TipoUsuarioPermiso {
        $relation = $this->findOrFail(
            $idTipoUsuarioPermiso
        );

        /*
         * Ya se encuentra inactiva.
         */
        if (! $relation->vigente()) {
            return $relation;
        }

        try {
            $result =
                $this->repository->deactivate(
                    $idTipoUsuarioPermiso
                );

            if (! $result) {
                throw new RuntimeException(
                    'La asignación no pudo ser desactivada.'
                );
            }

            $updated =
                $this->repository->findById(
                    $idTipoUsuarioPermiso
                );

            if ($updated === null) {
                throw new RuntimeException(
                    'La asignación fue desactivada, '
                    .'pero no pudo recuperarse.'
                );
            }

            if ($updated->vigente()) {
                throw new RuntimeException(
                    'La asignación continúa vigente '
                    .'después de la baja lógica.'
                );
            }

            $this->logger->database(
                'Asignación TipoUsuario-Permiso '
                .'desactivada correctamente.',
                [
                    'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

                    'IdTipoUsuario' => $updated->idTipoUsuario(),

                    'IdPermiso' => $updated->idPermiso(),
                ]
            );

            return $updated;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible desactivar la asignación '
                .'TipoUsuario-Permiso.',
                [
                    'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible desactivar la asignación.',
                0,
                $exception
            );
        }
    }

    /**
     * Reactiva una relación.
     *
     * Operación idempotente.
     */
    public function activate(
        int $idTipoUsuarioPermiso
    ): TipoUsuarioPermiso {
        $relation = $this->findOrFail(
            $idTipoUsuarioPermiso
        );

        /*
         * Ya se encuentra vigente.
         */
        if ($relation->vigente()) {
            return $relation;
        }

        try {
            $result =
                $this->repository->activate(
                    $idTipoUsuarioPermiso
                );

            if (! $result) {
                throw new RuntimeException(
                    'La asignación no pudo ser reactivada.'
                );
            }

            $updated =
                $this->repository->findById(
                    $idTipoUsuarioPermiso
                );

            if ($updated === null) {
                throw new RuntimeException(
                    'La asignación fue reactivada, '
                    .'pero no pudo recuperarse.'
                );
            }

            if (! $updated->vigente()) {
                throw new RuntimeException(
                    'La asignación no quedó vigente '
                    .'después de la reactivación.'
                );
            }

            $this->logger->database(
                'Asignación TipoUsuario-Permiso '
                .'reactivada correctamente.',
                [
                    'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

                    'IdTipoUsuario' => $updated->idTipoUsuario(),

                    'IdPermiso' => $updated->idPermiso(),
                ]
            );

            return $updated;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible reactivar la asignación '
                .'TipoUsuario-Permiso.',
                [
                    'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible reactivar la asignación.',
                0,
                $exception
            );
        }
    }

    /**
     * Valida los identificadores que forman
     * la relación.
     */
    private function validateRelationIds(
        int $idTipoUsuario,
        int $idPermiso
    ): void {
        if ($idTipoUsuario < 1) {
            throw new InvalidArgumentException(
                'Debe seleccionar un tipo de usuario válido.'
            );
        }

        if ($idPermiso < 1) {
            throw new InvalidArgumentException(
                'Debe seleccionar un permiso válido.'
            );
        }
    }

    /**
     * Valida que los catálogos relacionados existan
     * y se encuentren vigentes.
     */
    private function validateActiveCatalogs(
        int $idTipoUsuario,
        int $idPermiso
    ): void {
        $tipoUsuario =
            $this->tipoUsuarioService
                ->find(
                    $idTipoUsuario
                );

        if ($tipoUsuario === null) {
            throw new InvalidArgumentException(
                'El tipo de usuario seleccionado no existe.'
            );
        }

        if (! $tipoUsuario->vigente()) {
            throw new InvalidArgumentException(
                'El tipo de usuario seleccionado no está vigente.'
            );
        }

        $permiso =
            $this->permisoService
                ->find(
                    $idPermiso
                );

        if ($permiso === null) {
            throw new InvalidArgumentException(
                'El permiso seleccionado no existe.'
            );
        }

        if (! $permiso->vigente()) {
            throw new InvalidArgumentException(
                'El permiso seleccionado no está vigente.'
            );
        }
    }

    private function validateActiveCatalogsForUpdate(
        TipoUsuarioPermiso $current,
        int $idTipoUsuario,
        int $idPermiso
    ): void {
        if (
            $current->idTipoUsuario()
            !== $idTipoUsuario
        ) {
            $tipoUsuario =
                $this->tipoUsuarioService
                    ->find(
                        $idTipoUsuario
                    );

            if ($tipoUsuario === null) {
                throw new InvalidArgumentException(
                    'El tipo de usuario seleccionado no existe.'
                );
            }

            if (! $tipoUsuario->vigente()) {
                throw new InvalidArgumentException(
                    'El tipo de usuario seleccionado no está vigente.'
                );
            }
        }

        if (
            $current->idPermiso()
            !== $idPermiso
        ) {
            $permiso =
                $this->permisoService
                    ->find(
                        $idPermiso
                    );

            if ($permiso === null) {
                throw new InvalidArgumentException(
                    'El permiso seleccionado no existe.'
                );
            }

            if (! $permiso->vigente()) {
                throw new InvalidArgumentException(
                    'El permiso seleccionado no está vigente.'
                );
            }
        }
    }

    /**
     * Impide relaciones duplicadas.
     *
     * La combinación:
     *
     * IdTipoUsuario
     * + IdPermiso
     *
     * debe ser única, incluso cuando el
     * registro existente esté inactivo.
     */
    private function ensureRelationIsUnique(
        int $idTipoUsuario,
        int $idPermiso,
        ?int $ignoreId = null
    ): void {
        try {
            $existing =
                $this->repository->findByRelation(
                    $idTipoUsuario,
                    $idPermiso
                );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible validar la unicidad '
                .'de la asignación '
                .'TipoUsuario-Permiso.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'IdPermiso' => $idPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible validar la asignación.',
                0,
                $exception
            );
        }

        /*
         * No existe la combinación.
         */
        if ($existing === null) {
            return;
        }

        /*
         * Durante UPDATE permitimos que
         * findByRelation() encuentre el propio
         * registro que se está modificando.
         */
        if (
            $ignoreId !== null
            && $existing
                ->idTipoUsuarioPermiso()
                === $ignoreId
        ) {
            return;
        }

        /*
         * Un registro inactivo también conserva
         * la identidad de la relación.
         *
         * Debe reactivarse el existente en lugar
         * de crear uno nuevo.
         */
        throw new InvalidArgumentException(
            'Ya existe una asignación con el mismo '
            .'tipo de usuario y permiso.'
        );
    }
}
