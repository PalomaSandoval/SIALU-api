<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class IngestionCursor
{
    public function run(int $source, string $stream, callable $work): mixed
    {
        if ($source < 1 || ! in_array($stream, ['orion', 'paloalto-threat', 'paloalto-traffic'], true)) {
            throw new RuntimeException('Fuente o flujo de ingesta inválido.');
        }

        $diskName = config('sialu.staging.disk', 'local');
        if (config("filesystems.disks.{$diskName}.driver") !== 'local') {
            throw new RuntimeException('El cursor requiere almacenamiento local compartido por los procesos del backend.');
        }

        $disk = Storage::disk($diskName);
        $directory = 'ingestion/cursors';
        $disk->makeDirectory($directory);
        $cursorPath = $disk->path("{$directory}/source-{$source}-{$stream}.json");
        $lockHandle = @fopen($disk->path("{$directory}/source-{$source}-{$stream}.lock"), 'c');
        if ($lockHandle === false) {
            throw new RuntimeException('No se pudo abrir el bloqueo del cursor.');
        }

        if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);

            throw new RuntimeException('Este flujo ya se está recolectando.');
        }

        try {
            $contents = is_file($cursorPath) ? file_get_contents($cursorPath) : null;
            if ($contents === false) {
                throw new RuntimeException('No se pudo leer el archivo de avance.');
            }
            $state = $contents === null ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if ($state !== null && ! is_array($state)) {
                throw new RuntimeException('El archivo de avance no contiene un estado válido.');
            }

            $save = function (array $next) use ($cursorPath): void {
                $json = json_encode($next, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                File::replace($cursorPath, $json);
                $writtenHash = is_file($cursorPath) ? hash_file('sha256', $cursorPath) : false;
                if (! is_string($writtenHash) || ! hash_equals(hash('sha256', $json), $writtenHash)) {
                    throw new RuntimeException('No se pudo guardar el avance de ingesta.');
                }
            };

            return $work($state, $save);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
