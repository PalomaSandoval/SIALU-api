<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\StagingRepositoryInterface;
use App\Integrations\Orion\OrionAlertParser;
use App\Integrations\Orion\OrionAlertSource;
use App\Repositories\SqlServerOrionAlertRepository;
use App\Services\Ingestion\IngestionAttempts;
use App\Services\Ingestion\IngestionCursor;
use App\Services\Ingestion\IngestionDatabase;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Orion alert flow, separate from Orion.Events DOWN/UP: its own cursor file, lock and process name.
 * 1) AlertHistory triggers/resets, re-reading an overlap before the cursor (idempotent).
 * 2) Once history is caught up, the complete AlertActive snapshot reconciles active alerts,
 *    including old ones whose history Orion no longer retains.
 */
class SyncOrionAlertsCommand extends Command
{
    private const NEAR_EXPIRY_DAYS = 3;

    protected $signature = 'sialu:orion-alerts {--persist : Confirma escrituras en la base configurada} {--since= : Fecha inicial UTC YYYY-MM-DD; solo al crear avance} {--page-size=500} {--max-pages=20}';

    protected $description = 'Importa alertas disparadas y resueltas de Orion y concilia las activas, sin tocar DOWN/UP.';

    public function handle(IngestionCursor $cursor, IngestionAttempts $attempts, StagingRepositoryInterface $staging, OrionAlertParser $parser, SqlServerOrionAlertRepository $repository): int
    {
        if (! $this->option('persist') || ! config('sialu.persistence_enabled')) {
            $this->error('Se requiere --persist y SIALU_PERSISTENCE_ENABLED=true.');

            return self::FAILURE;
        }
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $size = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);
            $pages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
            if ($size === false || $size < 1 || $size > 5000 || $pages === false || $pages < 1 || $pages > 1000) {
                throw new RuntimeException('Límites inválidos.');
            }
            $overlap = (int) config('sialu.orion.alert_history_overlap_minutes', 60);
            if ($overlap < 0 || $overlap > 1440) {
                throw new RuntimeException('ORION_ALERT_HISTORY_OVERLAP_MINUTES debe estar entre 0 y 1440.');
            }
            $since = null;
            if ($this->option('since')) {
                $since = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('since'), 'UTC');
                if (! $since || $since->format('Y-m-d') !== $this->option('since')) {
                    throw new RuntimeException('Fecha inválida.');
                }
            }
            $sourceId = SourceIdentity::id('orion');
            $db = DB::connection(IngestionDatabase::name());
            $source = $db->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->first();
            if (! $source || $source->ZonaHoraria !== 'UTC') {
                throw new RuntimeException('Orion requiere una fuente vigente en UTC.');
            }
            if (! $db->getSchemaBuilder()->hasTable('movdAlertasOrion')) {
                throw new RuntimeException('Falta dbo.movdAlertasOrion; aplicar primero la migración 016 autorizada.');
            }
            $attempts->recover($sourceId, 15, true);
            $orion = app(OrionAlertSource::class);

            $cursor->run($sourceId, 'orion-alerts', function ($state, $save) use ($since, $size, $pages, $overlap, $sourceId, $orion, $staging, $parser, $repository) {
                $end = CarbonImmutable::now('UTC')->subMinute()->startOfSecond();
                $oldest = $orion->historialMasAntiguo();
                $horizon = $oldest === null ? null : CarbonImmutable::parse(rtrim($oldest, 'Z'), 'UTC');
                if ($state === null) {
                    if (! $since) {
                        throw new RuntimeException('Primera carga de alertas: indique --since=YYYY-MM-DD.');
                    }
                    if ($since >= $end) {
                        throw new RuntimeException('La fecha inicial debe ser anterior a la ventana consultada.');
                    }
                    $state = ['last_time' => $since->format('Y-m-d\TH:i:s.vP'), 'last_id' => '0', 'initial_since' => $since->format('Y-m-d\TH:i:s.vP'), 'history_gaps' => []];
                    if ($horizon && $since < $horizon) {
                        $this->warn('Orion conserva AlertHistory desde '.$horizon->format('Y-m-d H:i:s').' UTC; lo anterior solo se conocerá por AlertActive.');
                    }
                } elseif ($since) {
                    $this->warn('Ya existe avance; se conserva y no se reinicia con --since.');
                }
                $last = CarbonImmutable::parse($state['last_time']);
                if ($horizon && $last < $horizon && $last > CarbonImmutable::parse($state['initial_since'])) {
                    // History between the cursor and the oldest retained row may have expired before it was read.
                    $gap = ['desde' => $last->format('Y-m-d\TH:i:s.vP'), 'hasta' => $horizon->format('Y-m-d\TH:i:s.vP')];
                    if (! in_array($gap, $state['history_gaps'] ?? [], true)) {
                        $state['history_gaps'][] = $gap;
                        $save($state);
                    }
                    $this->warn('Posible historial expirado entre '.$gap['desde'].' y '.$gap['hasta'].'; ejecute con mayor frecuencia.');
                } elseif ($horizon && $last->diffInDays($horizon, true) < self::NEAR_EXPIRY_DAYS && $last > $horizon) {
                    $this->warn('El avance está a menos de '.self::NEAR_EXPIRY_DAYS.' días del registro más antiguo de AlertHistory.');
                }

                // Controlled re-read: start before the acknowledged position; persistence is idempotent.
                $position = [$last->subMinutes($overlap)->max(CarbonImmutable::parse($state['initial_since'])), '0'];
                if ($overlap === 0) {
                    $position = [$last, $state['last_id']];
                }
                $caughtUp = false;
                for ($page = 0; $page < $pages; $page++) {
                    $raw = $orion->obtenerPaginaHistorial($position[0], $position[1], $end, $size);
                    if (! $raw) {
                        $caughtUp = true;
                        break;
                    }
                    $uuid = $staging->store($sourceId, 'orion-alert-history', $raw);
                    $this->line('Lote crudo: '.$uuid);
                    $rows = $this->parse($raw, fn ($row) => $parser->parseHistory($row), $sourceId, $uuid);
                    foreach ($rows as $row) {
                        if ($row['occurred_at'] > $end || $row['occurred_at'] < $position[0]
                            || ($row['occurred_at'] == $position[0] && (int) $row['history_id'] <= (int) $position[1])) {
                            throw new RuntimeException('Página de AlertHistory sin orden/avance válido.');
                        }
                        $position = [$row['occurred_at'], $row['history_id']];
                    }
                    $r = $repository->persistHistory($sourceId, $rows, $uuid);
                    // Never acknowledge a position until its transaction committed; overlap never moves the cursor back.
                    if ($position[0] > $last || ($position[0] == $last && (int) $position[1] > (int) $state['last_id'])) {
                        $state['last_time'] = $position[0]->format('Y-m-d\TH:i:s.vP');
                        $state['last_id'] = $position[1];
                        $last = $position[0];
                    }
                    $save($state);
                    $this->info('Historial página '.($page + 1).'; intento '.$r['attempt_id'].'; nuevas '.$r['stored'].'; actualizadas '.$r['updated'].'; duplicadas '.$r['duplicates']);
                    if (count($raw) < $size) {
                        $caughtUp = true;
                        break;
                    }
                }
                if (! $caughtUp) {
                    $this->warn('Límite de páginas alcanzado; las activas se concilian cuando el historial esté al día. Vuelva a ejecutar.');

                    return;
                }
                if ($end > $last) {
                    // Everything up to $end was read; rows committed late inside the overlap are re-read next run.
                    $state['last_time'] = $end->format('Y-m-d\TH:i:s.vP');
                    $state['last_id'] = '0';
                    $save($state);
                }

                $raw = $orion->obtenerActivas();
                if (count($raw) > OrionAlertSource::ACTIVE_LIMIT) {
                    throw new RuntimeException('AlertActive supera '.OrionAlertSource::ACTIVE_LIMIT.' filas; no se concilia una instantánea incompleta.');
                }
                $uuid = $staging->store($sourceId, 'orion-alert-active', $raw);
                $this->line('Lote crudo: '.$uuid);
                $rows = $this->parse($raw, fn ($row) => $parser->parseActive($row), $sourceId, $uuid);
                $r = $repository->reconcileActive($sourceId, $rows, $uuid, $horizon);
                $state['last_reconciled_at'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.vP');
                $save($state);
                $this->info('Activas '.count($rows).'; intento '.$r['attempt_id'].'; nuevas '.$r['stored'].'; actualizadas '.$r['updated'].'; sin cambios '.$r['duplicates'].'; ya no activas '.$r['resolved']);
            });

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se completó la sincronización de alertas; el avance no reconoce la página fallida. Tipo: '.class_basename($e));
            if ($e instanceof RuntimeException && get_class($e) === RuntimeException::class && ! str_contains($e->getMessage(), 'Orion respondió')) {
                $this->line($e->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function parse(array $raw, callable $parse, int $sourceId, string $uuid): array
    {
        $rows = [];
        $errors = [];
        foreach ($raw as $index => $row) {
            try {
                if (! is_array($row)) {
                    throw new \InvalidArgumentException('Fila de origen inválida.');
                }
                $rows[] = $parse($row);
            } catch (\InvalidArgumentException) {
                $errors[] = ['row' => $index + 1];
            }
        }
        if ($errors) {
            $id = app(IngestionAttempts::class)->reject($sourceId, SqlServerOrionAlertRepository::PROCESS, $uuid, count($raw), $errors);
            throw new RuntimeException('Lote de alertas rechazado. Intento '.$id);
        }

        return $rows;
    }
}
