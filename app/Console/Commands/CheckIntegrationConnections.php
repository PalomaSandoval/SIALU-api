<?php

namespace App\Console\Commands;

use App\Integrations\Orion\OrionClient;
use App\Integrations\PaloAlto\PaloAltoApiSource;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckIntegrationConnections extends Command
{
    protected $signature = 'sialu:integrations-check {--database : Comprobar por lectura las fuentes configuradas en SQL Server}';
    protected $description = 'Comprueba conexiones a Orion y Palo Alto sin importar ni mostrar datos o credenciales';

    public function handle(): int
    {
        $ok = true;
        foreach (['orion', 'paloalto'] as $adapter) {
            $required = $adapter === 'orion' ? ['host', 'username', 'password'] : ['host', 'api_key'];
            $missing = array_filter($required, fn ($key) => trim((string) config("services.{$adapter}.{$key}")) === '');
            if ($missing) {
                $this->error(strtoupper($adapter).': configuración incompleta ('.implode(', ', $missing).').');
                $ok = false;
                continue;
            }
            try {
                if ($adapter === 'orion') {
                    app(OrionClient::class)->query('SELECT TOP 1 NodeID FROM Orion.Nodes');
                } else {
                    app(PaloAltoApiSource::class)->obtenerLogs('traffic', 1);
                }
                $this->info(strtoupper($adapter).': conexión y consulta de lectura correctas.');
                if (! filter_var(config("services.{$adapter}.ssl_verify", true), FILTER_VALIDATE_BOOLEAN)) {
                    $this->warn(strtoupper($adapter).': la validación del certificado TLS está desactivada en la configuración.');
                }
            } catch (Throwable $e) {
                // Do not print provider bodies, hostnames, credentials or returned data.
                $message = strtolower($e->getMessage());
                $reason = match (true) {
                    str_contains($message, 'certificate'), str_contains($message, 'ssl'), str_contains($message, 'tls') => 'falló la validación o negociación TLS',
                    str_contains($message, '401'), str_contains($message, '403') => 'autenticación rechazada o permisos insuficientes',
                    str_contains($message, 'resolve') => 'no se pudo resolver el servidor',
                    str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'se agotó el tiempo de conexión o respuesta',
                    str_contains($message, 'connect') => 'no se pudo establecer la conexión',
                    default => 'la consulta falló; revisar acceso, permisos y configuración',
                };
                $this->error(strtoupper($adapter).': '.$reason.'.');
                $ok = false;
            }
        }
        if ($this->option('database')) {
            try {
                IngestionDatabase::verifyConfiguredConnection();
                foreach (['orion', 'paloalto'] as $adapter) {
                    $id = SourceIdentity::id($adapter);
                    $source = DB::connection(IngestionDatabase::name())->table('dbo.catcFuentes')->where('IdFuente', $id)->first(['Vigente', 'ZonaHoraria']);
                    if (! $source || ! $source->Vigente) {
                        $this->error(strtoupper($adapter).': IdFuente inexistente o inactivo.');
                        $ok = false;
                    } elseif ($adapter === 'orion' && $source->ZonaHoraria !== 'UTC') {
                        $this->error('ORION: la fuente requiere ZonaHoraria UTC.');
                        $ok = false;
                    } else {
                        new \DateTimeZone($source->ZonaHoraria);
                        $this->info(strtoupper($adapter).': fuente vigente y zona horaria válidas en SQL Server.');
                    }
                }
            } catch (Throwable $e) {
                $this->error('SQL Server: no se pudo validar la conexión, los IDs configurados o las fuentes. No se modificaron registros.');
                $ok = false;
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
