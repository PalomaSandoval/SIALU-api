<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Models\Catalogos\Permiso;
use App\Repositories\Catalogos\PermisoRepository;
use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PermisoService
{
    private const MAX_CLAVE_LENGTH = 50;

    private const MAX_DESCRIPCION_LENGTH = 100;

    private PermisoRepository $repository;

    private Logger $logger;

    public function __construct(
        PermisoRepository $repository,
        Logger $logger
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * Obtiene todos los permisos.
     *
     * @return array<int, Permiso>
     */
    public function all(): array
    {
        try {
            return $this->repository->all();

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible obtener el catálogo de permisos.',
                [
                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar los permisos.',
                0,
                $exception
            );
        }
    }

    /**
     * Obtiene todos los permisos vigentes.
     *
     * @return array<int, Permiso>
     */
    public function active(): array
    {
        try {
            return $this->repository->active();

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible obtener el catálogo de permisos vigentes.',
                [
                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar los permisos vigentes.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca un permiso por ID.
     */
    public function find(
        int $idPermiso
    ): ?Permiso {
        if ($idPermiso < 1) {
            return null;
        }

        try {
            return $this->repository
                ->findById(
                    $idPermiso
                );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible consultar el permiso.',
                [
                    'IdPermiso' => $idPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar el permiso.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca un permiso por ID.
     *
     * Genera excepción si no existe.
     */
    public function findOrFail(
        int $idPermiso
    ): Permiso {
        $permiso =
            $this->find(
                $idPermiso
            );

        if ($permiso === null) {
            throw new RuntimeException(
                'El permiso solicitado no existe.'
            );
        }

        return $permiso;
    }

    /**
     * Busca un permiso mediante su clave.
     */
    public function findByClave(
        string $clave
    ): ?Permiso {
        $clave =
            $this->normalizeClave(
                $clave
            );

        try {
            return $this->repository
                ->findByClave(
                    $clave
                );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible consultar '
                .'el permiso por clave.',
                [
                    'Clave' => $clave,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar '
                .'el permiso por clave.',
                0,
                $exception
            );
        }
    }

    /**
     * Registra un nuevo permiso.
     */
    public function create(
        string $clave,
        string $descripcion
    ): Permiso {
        /*
         * =====================================================
         * VALIDACIÓN DE NEGOCIO
         * =====================================================
         *
         * Estas validaciones ocurren antes del try de
         * persistencia para conservar InvalidArgumentException
         * y permitir que el Controller muestre mensajes flash.
         */

        $clave =
            $this->normalizeClave(
                $clave
            );

        $descripcion =
            $this->normalizeDescripcion(
                $descripcion
            );

        $this->ensureClaveIsUnique(
            $clave
        );

        try {
            $idPermiso =
                $this->repository
                    ->create(
                        $clave,
                        $descripcion
                    );

            /*
             * Recuperamos el registro desde SQL Server
             * para devolver el estado definitivo.
             */
            $permiso =
                $this->repository
                    ->findById(
                        $idPermiso
                    );

            if ($permiso === null) {
                throw new RuntimeException(
                    'El permiso fue registrado, '
                    .'pero no pudo recuperarse.'
                );
            }

            $this->logger->database(
                'Permiso creado correctamente.',
                [
                    'IdPermiso' => $permiso->idPermiso(),

                    'Clave' => $permiso->clave(),

                    'Descripcion' => $permiso->descripcion(),

                    'Vigente' => $permiso->vigente(),
                ]
            );

            return $permiso;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible crear el permiso.',
                [
                    'Clave' => $clave,

                    'Descripcion' => $descripcion,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible registrar el permiso.',
                0,
                $exception
            );
        }
    }

    /**
     * Actualiza un permiso.
     *
     * Vigente NO se modifica mediante update().
     */
    public function update(
        int $idPermiso,
        string $clave,
        string $descripcion
    ): Permiso {
        if ($idPermiso < 1) {
            throw new InvalidArgumentException(
                'IdPermiso no válido.'
            );
        }

        /*
         * Confirmamos que exista antes de continuar.
         */
        $actual =
            $this->findOrFail(
                $idPermiso
            );

        $clave =
            $this->normalizeClave(
                $clave
            );

        $descripcion =
            $this->normalizeDescripcion(
                $descripcion
            );

        /*
         * =====================================================
         * UPDATE IDEMPOTENTE
         * =====================================================
         *
         * Si después de normalizar no existe ningún cambio,
         * evitamos ejecutar Acción 3 innecesariamente.
         */

        if (
            $actual->clave() === $clave
            && $actual->descripcion() === $descripcion
        ) {
            return $actual;
        }

        /*
         * La clave no puede pertenecer a otro permiso.
         */
        $this->ensureClaveIsUnique(
            $clave,
            $idPermiso
        );

        try {
            $updated =
                $this->repository
                    ->update(
                        $idPermiso,
                        $clave,
                        $descripcion
                    );

            if (! $updated) {
                throw new RuntimeException(
                    'No se encontró el permiso '
                    .'que se desea modificar.'
                );
            }

            $permiso =
                $this->repository
                    ->findById(
                        $idPermiso
                    );

            if ($permiso === null) {
                throw new RuntimeException(
                    'El permiso fue actualizado, '
                    .'pero no pudo recuperarse.'
                );
            }

            $this->logger->database(
                'Permiso actualizado correctamente.',
                [
                    'IdPermiso' => $idPermiso,

                    'Clave' => $clave,

                    'Descripcion' => $descripcion,
                ]
            );

            return $permiso;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible actualizar el permiso.',
                [
                    'IdPermiso' => $idPermiso,

                    'Clave' => $clave,

                    'Descripcion' => $descripcion,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible modificar el permiso.',
                0,
                $exception
            );
        }
    }

    /**
     * Realiza la baja lógica.
     *
     * Operación idempotente.
     */
    public function deactivate(
        int $idPermiso
    ): Permiso {
        $permiso =
            $this->findOrFail(
                $idPermiso
            );

        /*
         * Si ya está inactivo, no hacemos nada.
         */
        if (! $permiso->vigente()) {
            return $permiso;
        }

        try {
            $result =
                $this->repository
                    ->deactivate(
                        $idPermiso
                    );

            if (! $result) {
                throw new RuntimeException(
                    'El permiso no pudo ser desactivado.'
                );
            }

            $updated =
                $this->repository
                    ->findById(
                        $idPermiso
                    );

            if ($updated === null) {
                throw new RuntimeException(
                    'El permiso fue desactivado, '
                    .'pero no pudo recuperarse.'
                );
            }

            if ($updated->vigente()) {
                throw new RuntimeException(
                    'El permiso continúa vigente '
                    .'después de la baja lógica.'
                );
            }

            $this->logger->database(
                'Permiso desactivado correctamente.',
                [
                    'IdPermiso' => $idPermiso,

                    'Clave' => $updated->clave(),
                ]
            );

            return $updated;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible desactivar el permiso.',
                [
                    'IdPermiso' => $idPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible desactivar el permiso.',
                0,
                $exception
            );
        }
    }

    /**
     * Reactiva un permiso.
     *
     * Operación idempotente.
     */
    public function activate(
        int $idPermiso
    ): Permiso {
        $permiso =
            $this->findOrFail(
                $idPermiso
            );

        /*
         * Si ya está vigente, no hacemos nada.
         */
        if ($permiso->vigente()) {
            return $permiso;
        }

        try {
            $result =
                $this->repository
                    ->activate(
                        $idPermiso
                    );

            if (! $result) {
                throw new RuntimeException(
                    'El permiso no pudo ser reactivado.'
                );
            }

            $updated =
                $this->repository
                    ->findById(
                        $idPermiso
                    );

            if ($updated === null) {
                throw new RuntimeException(
                    'El permiso fue reactivado, '
                    .'pero no pudo recuperarse.'
                );
            }

            if (! $updated->vigente()) {
                throw new RuntimeException(
                    'El permiso no quedó vigente '
                    .'después de la reactivación.'
                );
            }

            $this->logger->database(
                'Permiso reactivado correctamente.',
                [
                    'IdPermiso' => $idPermiso,

                    'Clave' => $updated->clave(),
                ]
            );

            return $updated;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible reactivar el permiso.',
                [
                    'IdPermiso' => $idPermiso,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible reactivar el permiso.',
                0,
                $exception
            );
        }
    }

    /**
     * =========================================================
     * NORMALIZACIÓN DE CLAVE
     * =========================================================
     *
     * Formato esperado:
     *
     * USUARIO_LDAP_CONSULTAR
     * PASSWORD_REVELAR
     * CATALOGO_MODIFICAR
     */
    private function normalizeClave(
        string $clave
    ): string {
        $clave =
            trim(
                $clave
            );

        if ($clave === '') {
            throw new InvalidArgumentException(
                'La clave es obligatoria.'
            );
        }

        /*
         * La clave técnica siempre se maneja
         * en mayúsculas.
         */
        $clave =
            mb_strtoupper(
                $clave,
                'UTF-8'
            );

        $length =
            mb_strlen(
                $clave,
                'UTF-8'
            );

        if (
            $length
            > self::MAX_CLAVE_LENGTH
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'La clave no puede exceder '
                    .'%d caracteres. '
                    .'Se recibieron %d caracteres.',
                    self::MAX_CLAVE_LENGTH,
                    $length
                )
            );
        }

        /*
         * Permitimos únicamente:
         *
         * A-Z
         * 0-9
         * _
         */
        if (
            preg_match(
                '/^[A-Z0-9_]+$/',
                $clave
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'La clave solo puede contener '
                .'letras, números y guion bajo (_).'
            );
        }

        /*
         * No permitimos:
         *
         * _USUARIO
         * USUARIO_
         */
        if (
            str_starts_with(
                $clave,
                '_'
            )
            || str_ends_with(
                $clave,
                '_'
            )
        ) {
            throw new InvalidArgumentException(
                'La clave no puede iniciar '
                .'ni terminar con guion bajo.'
            );
        }

        /*
         * Evita claves como:
         *
         * USUARIO__CONSULTAR
         */
        if (
            str_contains(
                $clave,
                '__'
            )
        ) {
            throw new InvalidArgumentException(
                'La clave no puede contener '
                .'guiones bajos consecutivos.'
            );
        }

        return $clave;
    }

    /**
     * =========================================================
     * NORMALIZACIÓN DE DESCRIPCIÓN
     * =========================================================
     */
    private function normalizeDescripcion(
        string $descripcion
    ): string {
        $descripcion =
            trim(
                $descripcion
            );

        /*
         * Espacios múltiples, tabs y saltos
         * de línea se convierten en un espacio.
         */
        $normalized =
            preg_replace(
                '/\s+/u',
                ' ',
                $descripcion
            );

        if ($normalized === null) {
            throw new RuntimeException(
                'No fue posible normalizar '
                .'la descripción.'
            );
        }

        $descripcion =
            trim(
                $normalized
            );

        if ($descripcion === '') {
            throw new InvalidArgumentException(
                'La descripción es obligatoria.'
            );
        }

        $length =
            mb_strlen(
                $descripcion,
                'UTF-8'
            );

        if (
            $length
            > self::MAX_DESCRIPCION_LENGTH
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'La descripción no puede exceder '
                    .'%d caracteres. '
                    .'Se recibieron %d caracteres.',
                    self::MAX_DESCRIPCION_LENGTH,
                    $length
                )
            );
        }

        return mb_strtoupper(
            $descripcion,
            'UTF-8'
        );
    }

    /**
     * =========================================================
     * UNICIDAD DE CLAVE
     * =========================================================
     *
     * La Clave identifica técnicamente al permiso.
     *
     * También consideramos duplicado un permiso
     * que actualmente tenga Vigente=false.
     */
    private function ensureClaveIsUnique(
        string $clave,
        ?int $ignoreId = null
    ): void {
        try {
            $existing =
                $this->repository
                    ->findByClave(
                        $clave
                    );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible validar la unicidad '
                .'de la clave del permiso.',
                [
                    'Clave' => $clave,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible validar '
                .'la clave del permiso.',
                0,
                $exception
            );
        }

        /*
         * No existe.
         */
        if ($existing === null) {
            return;
        }

        /*
         * Durante UPDATE se permite conservar
         * la clave del mismo registro.
         */
        if (
            $ignoreId !== null
            && $existing->idPermiso()
                === $ignoreId
        ) {
            return;
        }

        throw new InvalidArgumentException(
            'Ya existe un permiso '
            .'con la misma clave.'
        );
    }
}
