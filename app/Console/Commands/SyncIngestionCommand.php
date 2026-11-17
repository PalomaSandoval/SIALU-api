<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\StagingRepositoryInterface;
use App\Integrations\Orion\OrionEventSource;
use App\Integrations\Orion\OrionParser;
use App\Integrations\PaloAlto\PaloAltoApiSource;
use App\Integrations\PaloAlto\PaloAltoParser;
use App\Repositories\SqlServerOrionAvailabilityRepository;
use App\Repositories\SqlServerPaloAltoRepository;
use App\Services\Ingestion\IngestionAttempts;
use App\Services\Ingestion\IngestionCursor;
use App\Services\Ingestion\IngestionDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncIngestionCommand extends Command
{
    protected $signature = 'sialu:sync {service : orion, paloalto-threat o paloalto-traffic} {--persist : Confirma escrituras en la base configurada} {--since= : Fecha inicial UTC YYYY-MM-DD; solo al crear avance} {--source-timezone : Interpretar since en la zona de la fuente} {--page-size=100} {--max-pages=20}';

    protected $description = 'Recolecta y procesa páginas de una fuente institucional.';

    public function handle(IngestionCursor $cursor, IngestionAttempts $attempts, StagingRepositoryInterface $staging): int
    {
        if (! $this->option('persist')) {
            $this->error('Usa --persist para solicitar escrituras en la base configurada.');

            return self::FAILURE;
        }
        if (! config('sialu.persistence_enabled')) {
            $this->error('La persistencia está deshabilitada en la configuración.');

            return self::FAILURE;
        }
        try {
            IngestionDatabase::verifyConfiguredConnection();
            $service = (string) $this->argument('service');
            if (! in_array($service, ['orion', 'paloalto-threat', 'paloalto-traffic'], true)) {
                throw new RuntimeException('Flujo inválido.');
            }
            $size = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);
            $pages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
            if ($size === false || $size < 1 || $size > 5000 || $pages === false || $pages < 1 || $pages > 1000) {
                throw new RuntimeException('Límites inválidos.');
            }
            $date = (string) ($this->option('since') ?: now('UTC')->format('Y-m-d'));
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
            if (! $start || $start->format('Y-m-d') !== $date) {
                throw new RuntimeException('Fecha inválida.');
            }
            $orion = $service === 'orion';
            $sourceId = \App\Services\Ingestion\SourceIdentity::id($orion ? 'orion' : 'paloalto');
            $source = DB::connection(IngestionDatabase::name())->table('dbo.catcFuentes')->where('IdFuente', $sourceId)->where('Vigente', 1)->first();
            if (! $source) {
                throw new RuntimeException("No existe una fuente vigente '{$sourceId}' en dbo.catcFuentes.");
            }
            if ($orion && $source->ZonaHoraria !== 'UTC') {
                throw new RuntimeException('Orion requiere UTC.');
            }
            if (! $orion) {
                new \DateTimeZone($source->ZonaHoraria);
                config(['sialu.paloalto.timezone' => $source->ZonaHoraria]);
            }
            if ($this->option('source-timezone')) {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $source->ZonaHoraria)->utc();
            }
            $attempts->recover((int) $source->IdFuente);
            $cursor->run((int) $source->IdFuente, $service, function ($state, $save) use ($orion, $source, $service, $size, $pages, $start, $staging) {
                if ($state && $this->option('since')) {
                    $this->warn('Ya existe avance; se conserva y no se reinicia con --since.');
                }
                $end = CarbonImmutable::now('UTC')->subMinute()->startOfSecond();
                if ($state === null && ! $this->option('since')) {
                    throw new RuntimeException('Primera carga: indique --since=YYYY-MM-DD.');
                }
                if ($state === null && $start >= $end) {
                    throw new RuntimeException('La fecha inicial debe ser anterior a la ventana consultada.');
                }
                if ($orion) {
                    if ($state === null && ! $this->option('since')) {
                        throw new RuntimeException('Primera carga Orion: indique --since=YYYY-MM-DD explícitamente.');
                    }
                    if ($state === null && $start >= $end) {
                        throw new RuntimeException('La fecha inicial debe ser anterior a la ventana consultada.');
                    }
                    $state ??= ['last_time' => $start->toIso8601String(), 'last_id' => '0', 'read_from' => $start->toIso8601String()];
                    // read_from/read_until prove which span of Orion.Events was read without gaps (EventTime <= read_until).
                    // A cursor from before these keys only proves continuity from its current position onwards.
                    $state['read_from'] ??= CarbonImmutable::parse($state['last_time'])->utc()->format('Y-m-d\TH:i:s.vP');
                } else {
                    $state ??= ['completed_until' => $start->toIso8601String(), 'initial_since' => $start->toIso8601String()];
                    if (! isset($state['window_end'])) {
                        $begin = CarbonImmutable::parse($state['completed_until'])->subMinutes(10)->max(CarbonImmutable::parse($state['initial_since']));
                        if ($begin >= $end) {
                            $this->info('Sin ventana nueva por recolectar.');

                            return;
                        }
                        $state += ['window_start' => $begin->toIso8601String(), 'window_end' => $end->toIso8601String(), 'offset' => 0];
                        $save($state); // Freeze retrieval window before collecting its pages.
                    }
                }
                for ($page = 0; $page < $pages; $page++) {
                    if ($orion) {
                        $raw = app(OrionEventSource::class)->obtenerPaginaDisponibilidad(CarbonImmutable::parse($state['last_time']), $state['last_id'], $end, $size);
                    } else {
                        $begin = CarbonImmutable::parse($state['window_start'])->setTimezone($source->ZonaHoraria)->format('Y/m/d H:i:s');
                        $finish = CarbonImmutable::parse($state['window_end'])->setTimezone($source->ZonaHoraria)->format('Y/m/d H:i:s');
                        $query = "(receive_time geq '{$begin}') and (receive_time leq '{$finish}')";
                        $raw = app(PaloAltoApiSource::class)->obtenerLogs(substr($service, 9), $size, $query, (int) $state['offset'], 'forward');
                    }
                    if (! $raw) {
                        if (! $orion) {
                            $state = ['completed_until' => $state['window_end'], 'initial_since' => $state['initial_since']];
                        } else {
                            $state['read_until'] = $end->format('Y-m-d\TH:i:s.vP'); // Every event up to $end has been read.
                        }
                        $save($state); // Preserve explicit initial boundary, including an empty first page.
                        $this->info('Flujo al día para la ventana consultada.');

                        return;
                    }
                    $type = $orion ? 'orion-node-availability' : $service;
                    $uuid = $staging->store((int) $source->IdFuente, $type, $raw);
                    $batch = $staging->load($uuid);
                    $this->line('Lote crudo: '.$uuid);
                    $parser = $orion ? app(OrionParser::class) : app(PaloAltoParser::class);
                    $events = [];
                    $errors = [];
                    foreach ($raw as $index => $row) {
                        try {
                            if (! is_array($row)) {
                                throw new \InvalidArgumentException('Fila de origen inválida.');
                            }
                            $e = $parser->parse($row);
                            if (! $orion && $e['event_type'] !== strtoupper(substr($service, 9))) {
                                throw new \InvalidArgumentException('Tipo inesperado.');
                            }
                            $events[] = $e;
                        } catch (\InvalidArgumentException $e) {
                            $errors[] = ['row' => $index + 1];
                        }
                    }
                    if ($errors) {
                        $id = app(IngestionAttempts::class)->reject((int) $source->IdFuente, $orion ? 'orion-node-v1' : 'paloalto-v1', $uuid, count($raw), $errors);
                        throw new RuntimeException('Lote rechazado. Intento '.$id);
                    }
                    // Never acknowledge a source position until the operational transaction has completed.
                    if ($orion) {
                        $priorTime = CarbonImmutable::parse($state['last_time']);
                        $priorId = $state['last_id'];
                        foreach ($events as $e) {
                            if ($e['occurred_at'] > $end || $e['occurred_at'] < $priorTime || ($e['occurred_at'] == $priorTime && (int) $e['external_id'] <= (int) $priorId)) {
                                throw new RuntimeException('Página Orion sin orden/avance válido.');
                            }
                            $priorTime = $e['occurred_at'];
                            $priorId = $e['external_id'];
                        }
                        $r = app(SqlServerOrionAvailabilityRepository::class)->persist((int) $source->IdFuente, $events, $uuid, $batch['collected_at']);
                        $state = ['last_time' => $priorTime->format('Y-m-d\TH:i:s.vP'), 'last_id' => $priorId]
                            + array_intersect_key($state, ['read_from' => true, 'read_until' => true]);
                    } else {
                        $r = app(SqlServerPaloAltoRepository::class)->persist((int) $source->IdFuente, $events, $uuid, $batch['collected_at']);
                        $state['offset'] += count($raw);
                    }
                    $save($state);
                    $this->info('Página '.($page + 1).'; intento '.$r['attempt_id'].'; insertados '.$r['stored'].'; duplicados '.$r['duplicates']);
                    if (count($raw) < $size) {
                        if (! $orion) {
                            $state = ['completed_until' => $state['window_end'], 'initial_since' => $state['initial_since']];
                        } else {
                            $state['read_until'] = $end->format('Y-m-d\TH:i:s.vP'); // A short page ends the read up to $end.
                        }
                        $save($state);
                        $this->info('Flujo al día para la ventana consultada.');

                        return;
                    }
                }
                $this->warn('Límite de páginas alcanzado; vuelve a ejecutar para continuar desde el avance guardado.');
            });

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se completó sincronización; el avance no reconoce la página fallida. Tipo: '.class_basename($e));
            if (get_class($e) === RuntimeException::class && ! str_contains($e->getMessage(), 'Respuesta:') && ! str_contains($e->getMessage(),'API')) {
                $this->line($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
