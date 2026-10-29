<?php

declare(strict_types=1);

namespace App\Services\Catalogos;

use App\Models\Catalogos\TipoUsuario;
use App\Repositories\Catalogos\TipoUsuarioRepository;
use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TipoUsuarioService
{
    private const MAX_DESCRIPTION_LENGTH = 50;

    private TipoUsuarioRepository $repository;

    private Logger $logger;

    public function __construct(
        TipoUsuarioRepository $repository,
        Logger $logger
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * Obtiene todos los tipos de usuario.
     *
     * @return array<int, TipoUsuario>
     */
    public function all(): array
    {
        try {
            return $this->repository->all();

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible obtener el catálogo de tipos de usuarios.',
                [
                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar los tipos de usuarios.',
                0,
                $exception
            );
        }
    }

    /**
     * Obtiene todos los tipos de usuario vigentes.
     *
     * @return array<int, TipoUsuario>
     */
    public function active(): array
    {
        try {
            return $this->repository->active();

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible obtener el catálogo '
                .'de tipos de usuarios vigentes.',
                [
                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar '
                .'los tipos de usuarios vigentes.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca un tipo de usuario por ID.
     */
    public function find(
        int $idTipoUsuario
    ): ?TipoUsuario {
        if ($idTipoUsuario < 1) {
            return null;
        }

        try {
            return $this->repository->findById(
                $idTipoUsuario
            );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible consultar el tipo de usuario.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar el tipo de usuario.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca un tipo de usuario o genera excepción.
     */
    public function findOrFail(
        int $idTipoUsuario
    ): TipoUsuario {
        $tipoUsuario =
            $this->find(
                $idTipoUsuario
            );

        if ($tipoUsuario === null) {
            throw new RuntimeException(
                'El tipo de usuario solicitado no existe.'
            );
        }

        return $tipoUsuario;
    }

    /**
     * Registra un nuevo tipo de usuario.
     */
    public function create(
        string $descripcion
    ): TipoUsuario {
        /*
         * Las validaciones de negocio deben ocurrir
         * ANTES del try para conservar
         * InvalidArgumentException.
         */
        $descripcion =
            $this->normalizeDescription(
                $descripcion
            );

        $this->ensureDescriptionIsUnique(
            $descripcion
        );

        try {
            $idTipoUsuario =
                $this->repository->create(
                    $descripcion
                );

            $tipoUsuario =
                $this->repository->findById(
                    $idTipoUsuario
                );

            if ($tipoUsuario === null) {
                throw new RuntimeException(
                    'El tipo de usuario fue registrado, '
                    .'pero no pudo recuperarse.'
                );
            }

            $this->logger->database(
                'Tipo de usuario creado correctamente.',
                [
                    'IdTipoUsuario' => $tipoUsuario->idTipoUsuario(),

                    'Descripcion' => $tipoUsuario->descripcion(),

                    'Vigente' => $tipoUsuario->vigente(),
                ]
            );

            return $tipoUsuario;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible crear el tipo de usuario.',
                [
                    'Descripcion' => $descripcion,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible registrar el tipo de usuario.',
                0,
                $exception
            );
        }
    }

    /**
     * Modifica la descripción.
     *
     * Vigente NO se modifica aquí.
     */
    public function update(
        int $idTipoUsuario,
        string $descripcion
    ): TipoUsuario {
        if ($idTipoUsuario < 1) {
            throw new InvalidArgumentException(
                'IdTipoUsuario no válido.'
            );
        }

        /*
         * Confirmamos primero que exista.
         */
        $actual =
            $this->findOrFail(
                $idTipoUsuario
            );

        /*
         * Validación y normalización antes
         * de entrar al try de persistencia.
         */
        $descripcion =
            $this->normalizeDescription(
                $descripcion
            );

        /*
         * Si no hubo cambios reales,
         * evitamos un UPDATE innecesario.
         */
        if (
            $actual->descripcion()
            === $descripcion
        ) {
            return $actual;
        }

        /*
         * Verifica que la descripción no
         * pertenezca a otro registro.
         */
        $this->ensureDescriptionIsUnique(
            $descripcion,
            $idTipoUsuario
        );

        try {
            $updated =
                $this->repository->update(
                    $idTipoUsuario,
                    $descripcion
                );

            if (! $updated) {
                throw new RuntimeException(
                    'No se encontró el tipo de usuario '
                    .'que se desea modificar.'
                );
            }

            $tipoUsuario =
                $this->repository->findById(
                    $idTipoUsuario
                );

            if ($tipoUsuario === null) {
                throw new RuntimeException(
                    'El tipo de usuario fue actualizado, '
                    .'pero no pudo recuperarse.'
                );
            }

            $this->logger->database(
                'Tipo de usuario actualizado correctamente.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'Descripcion' => $descripcion,
                ]
            );

            return $tipoUsuario;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible actualizar el tipo de usuario.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'Descripcion' => $descripcion,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible modificar el tipo de usuario.',
                0,
                $exception
            );
        }
    }

    /**
     * Realiza baja lógica.
     *
     * Operación idempotente.
     */
    public function deactivate(
        int $idTipoUsuario
    ): TipoUsuario {
        $tipoUsuario =
            $this->findOrFail(
                $idTipoUsuario
            );

        /*
         * Ya está inactivo.
         */
        if (! $tipoUsuario->vigente()) {
            return $tipoUsuario;
        }

        try {
            $result =
                $this->repository->deactivate(
                    $idTipoUsuario
                );

            if (! $result) {
                throw new RuntimeException(
                    'El tipo de usuario no pudo ser desactivado.'
                );
            }

            $updated =
                $this->repository->findById(
                    $idTipoUsuario
                );

            if ($updated === null) {
                throw new RuntimeException(
                    'El tipo de usuario fue desactivado, '
                    .'pero no pudo recuperarse.'
                );
            }

            if ($updated->vigente()) {
                throw new RuntimeException(
                    'El tipo de usuario continúa vigente '
                    .'después de la baja lógica.'
                );
            }

            $this->logger->database(
                'Tipo de usuario desactivado correctamente.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'Descripcion' => $updated->descripcion(),
                ]
            );

            return $updated;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible desactivar el tipo de usuario.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible desactivar el tipo de usuario.',
                0,
                $exception
            );
        }
    }

    /**
     * Reactiva un tipo de usuario.
     *
     * Operación idempotente.
     */
    public function activate(
        int $idTipoUsuario
    ): TipoUsuario {
        $tipoUsuario =
            $this->findOrFail(
                $idTipoUsuario
            );

        /*
         * Ya está vigente.
         */
        if ($tipoUsuario->vigente()) {
            return $tipoUsuario;
        }

        try {
            $result =
                $this->repository->activate(
                    $idTipoUsuario
                );

            if (! $result) {
                throw new RuntimeException(
                    'El tipo de usuario no pudo ser reactivado.'
                );
            }

            $updated =
                $this->repository->findById(
                    $idTipoUsuario
                );

            if ($updated === null) {
                throw new RuntimeException(
                    'El tipo de usuario fue reactivado, '
                    .'pero no pudo recuperarse.'
                );
            }

            if (! $updated->vigente()) {
                throw new RuntimeException(
                    'El tipo de usuario no quedó vigente '
                    .'después de la reactivación.'
                );
            }

            $this->logger->database(
                'Tipo de usuario reactivado correctamente.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'Descripcion' => $updated->descripcion(),
                ]
            );

            return $updated;

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible reactivar el tipo de usuario.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible reactivar el tipo de usuario.',
                0,
                $exception
            );
        }
    }

    /**
     * Normaliza y valida Descripcion.
     */
    private function normalizeDescription(
        string $descripcion
    ): string {
        /*
         * Elimina espacios exteriores.
         */
        $descripcion = trim(
            $descripcion
        );

        /*
         * Convierte espacios múltiples,
         * tabs y saltos de línea en un
         * único espacio.
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

        $descripcion = trim(
            $normalized
        );

        /*
         * Campo obligatorio.
         */
        if ($descripcion === '') {
            throw new InvalidArgumentException(
                'La descripción es obligatoria.'
            );
        }

        /*
         * Se valida longitud ANTES de enviarla
         * al Repository/SQL Server.
         *
         * Así impedimos truncamientos.
         */
        $length =
            mb_strlen(
                $descripcion,
                'UTF-8'
            );

        if (
            $length
            > self::MAX_DESCRIPTION_LENGTH
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'La descripción no puede exceder '
                    .'%d caracteres. '
                    .'Se recibieron %d caracteres.',
                    self::MAX_DESCRIPTION_LENGTH,
                    $length
                )
            );
        }

        /*
         * Normalización definitiva.
         */
        return mb_strtoupper(
            $descripcion,
            'UTF-8'
        );
    }

    /**
     * Verifica que no exista otro
     * TipoUsuario con la misma descripción.
     */
    private function ensureDescriptionIsUnique(
        string $descripcion,
        ?int $ignoreId = null
    ): void {
        try {
            $existing =
                $this->repository
                    ->findByDescription(
                        $descripcion
                    );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible verificar la unicidad '
                .'del tipo de usuario.',
                [
                    'Descripcion' => $descripcion,

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible validar la descripción '
                .'del tipo de usuario.',
                0,
                $exception
            );
        }

        if ($existing === null) {
            return;
        }

        /*
         * Durante edición permitimos conservar
         * la descripción del propio registro.
         */
        if (
            $ignoreId !== null
            && $existing->idTipoUsuario()
                === $ignoreId
        ) {
            return;
        }

        /*
         * También se considera duplicado si
         * el registro encontrado está inactivo.
         *
         * En ese caso debe reactivarse el
         * existente, no crear uno nuevo.
         */
        throw new InvalidArgumentException(
            'Ya existe un tipo de usuario '
            .'con la misma descripción.'
        );
    }
}
