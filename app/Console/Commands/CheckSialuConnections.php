<?php

namespace App\Console\Commands;


use App\Services\Catalogos\WebCatalogService;
use App\Services\LdapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckSialuConnections extends Command
{
    protected $signature = 'sialu:check {--catalogs : Comprobar también los procedimientos de catálogos mediante lectura}';

    protected $description = 'Verifica SQL Server y la cuenta de lectura LDAP sin modificar registros';

    public function handle(): int
    {
        $success = true;
        try {
            DB::connection('sqlsrv')->select('SELECT 1 AS connection_ok');
            $this->info('SQL Server: conexión correcta.');
            if ($this->option('catalogs')) {
                $catalogs = app(WebCatalogService::class);
                foreach (['tipos-usuarios', 'permisos', 'tipos-usuarios-permisos'] as $catalog) {
                    $this->info($catalog.': '.count($catalogs->all($catalog)).' registros consultados.');
                }
            }
        } catch (Throwable $exception) {
            $success = false;
            $this->error('SQL Server: conexión fallida. Revise las variables DB_* y el acceso al servidor.');
            $this->line('Código de diagnóstico: '.(string) $exception->getCode());
            $reason = match (true) {
                str_contains(strtolower($exception->getMessage()), 'encryption not supported') => 'El controlador SQL Server del equipo no admite el cifrado solicitado.',
                str_contains(strtolower($exception->getMessage()), 'certificate') => 'Certificado TLS rechazado.',
                str_contains(strtolower($exception->getMessage()), 'login timeout') => 'Tiempo de conexión agotado.',
                str_contains(strtolower($exception->getMessage()), 'tcp provider') => 'El servidor no respondió a la conexión TCP.',
                str_contains(strtolower($exception->getMessage()), 'login failed') => 'Credenciales SQL Server rechazadas.',
                default => 'No fue posible establecer la conexión con el controlador SQL Server.',
            };
            $this->line($reason);
        }
        try {
            $result = app(LdapService::class)->healthCheck();
            if (! $result['success']) {
                throw new \RuntimeException('LDAP no disponible.');
            }
            $this->info('LDAP: conexión y bind de lectura correctos.');
        } catch (Throwable $exception) {
            $success = false;
            $this->error('LDAP: comprobación fallida. Revise las variables LDAP_*, la cuenta de lectura y el acceso al servidor.');
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
