<?php
namespace App\Support;

use Illuminate\Session\FileSessionHandler;

/** Sesiones válidas hasta el cierre manual o la pérdida de la cookie. */
class PersistentFileSessionHandler extends FileSessionHandler
{
    public function read($sessionId): string|false
    {
        $path = $this->path.'/'.$sessionId;
        return $this->files->isFile($path) ? $this->files->sharedGet($path) : '';
    }

    public function gc($lifetime): int
    {
        // La antigüedad de un archivo no invalida una sesión.
        return 0;
    }
}
