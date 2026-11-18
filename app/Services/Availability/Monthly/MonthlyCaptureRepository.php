<?php

namespace App\Services\Availability\Monthly;

use App\Services\Ingestion\IngestionDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Storage of monthly availability captures (migration 020). A finished capture is immutable (also enforced by
 * triggers): its nodes, day coverage, outages and approved subtotals are written once while EN_PROCESO, then the
 * capture is closed with the SHA-256 of its canonical content. Reports and PDFs are rebuilt only from these rows.
 */
class MonthlyCaptureRepository
{
    /** A capture EN_PROCESO without a heartbeat for this long is closed as FALLIDA. */
    public const STALE_MINUTES = 10;

    public const FINAL = ['COMPLETA', 'PARCIAL', 'FALLIDA'];

    public function __construct(private ?ConnectionInterface $db = null)
    {
        $this->db ??= DB::connection(IngestionDatabase::name());
    }

    public function db(): ConnectionInterface
    {
        return $this->db;
    }

    /** @return array{id: int, creada: bool} the new request, or the one already pending or in progress. */
    public function request(string $origin, ?int $userId): array
    {
        return $this->db->transaction(function () use ($origin, $userId): array {
            $this->lock('SIALU:disp-mensual:solicitud');
            $open = $this->openRequest();
            if ($open !== null) {
                return ['id' => $open, 'creada' => false];
            }
            $id = $this->db->table('dbo.movdDispCaptura')->insertGetId([
                'Uuid' => (string) Str::uuid(), 'Origen' => $origin, 'IdUsuarioSolicita' => $userId,
                'FechaSolicitud' => self::now(), 'Estado' => 'PENDIENTE', 'VersionCalculo' => MonthlyReportBuilder::VERSION,
            ], 'IdCaptura');

            return ['id' => (int) $id, 'creada' => true];
        });
    }

    /** The capture request pending or in progress, if any. */
    public function openRequest(): ?int
    {
        $id = $this->db->table('dbo.movdDispCaptura')->whereIn('Estado', ['PENDIENTE', 'EN_PROCESO'])->orderBy('IdCaptura')->value('IdCaptura');

        return $id === null ? null : (int) $id;
    }

    /** Scheduled and manual captures requested in [$from, $to), oldest first (evidence imports are not attempts). */
    public function attempts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->db->table('dbo.movdDispCaptura')->whereIn('Origen', ['PROGRAMADA', 'MANUAL'])
            ->where('FechaSolicitud', '>=', $from->format('Y-m-d H:i:s.v'))->where('FechaSolicitud', '<', $to->format('Y-m-d H:i:s.v'))
            ->orderBy('IdCaptura')->get(['IdCaptura', 'Origen', 'Estado', 'Valida', 'MesAnterior', 'FechaSolicitud', 'FechaFin', 'Latido', 'MensajeError', 'MotivosInvalidez'])->all();
    }

    public function lastManualRequest(): ?CarbonImmutable
    {
        $at = $this->db->table('dbo.movdDispCaptura')->where('Origen', 'MANUAL')->max('FechaSolicitud');

        return $at === null ? null : CarbonImmutable::parse($at, 'UTC');
    }

    /** Closes captures whose process died. @return int closed */
    public function failStale(): int
    {
        $limit = CarbonImmutable::now('UTC')->subMinutes(self::STALE_MINUTES)->format('Y-m-d H:i:s.v');

        return $this->db->table('dbo.movdDispCaptura')->where('Estado', 'EN_PROCESO')
            ->where(fn ($q) => $q->where('Latido', '<', $limit)->orWhereNull('Latido'))
            ->update(['Estado' => 'FALLIDA', 'FechaFin' => self::now(), 'MensajeError' => 'El proceso dejó de responder; captura abandonada.']);
    }

    /** Takes the oldest pending request; null when there is none. */
    public function claim(): ?int
    {
        // OUTPUT without INTO is not allowed on a table with triggers: lock the row, then update it.
        return $this->db->transaction(function (): ?int {
            $row = $this->db->selectOne("SELECT TOP 1 IdCaptura FROM dbo.movdDispCaptura WITH (UPDLOCK, READPAST, ROWLOCK)
                WHERE Estado = 'PENDIENTE' ORDER BY IdCaptura");
            if ($row === null) {
                return null;
            }
            $now = self::now();
            $updated = $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $row->IdCaptura)->where('Estado', 'PENDIENTE')
                ->update(['Estado' => 'EN_PROCESO', 'FechaInicio' => $now, 'Latido' => $now]);

            return $updated === 1 ? (int) $row->IdCaptura : null;
        });
    }

    public function heartbeat(int $id, int $queried, ?int $total = null): void
    {
        $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $id)->where('Estado', 'EN_PROCESO')
            ->update(['Latido' => self::now(), 'NodosConsultados' => $queried] + ($total === null ? [] : ['TotalNodos' => $total]));
    }

    public function fail(int $id, string $message): void
    {
        $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $id)->where('Estado', 'EN_PROCESO')
            ->update(['Estado' => 'FALLIDA', 'FechaFin' => self::now(), 'MensajeError' => mb_substr($message, 0, 500)]);
    }

    /** Creates a capture directly EN_PROCESO (evidence import). */
    public function start(string $origin, ?string $evidence): int
    {
        $now = self::now();

        return (int) $this->db->table('dbo.movdDispCaptura')->insertGetId([
            'Uuid' => (string) Str::uuid(), 'Origen' => $origin, 'FechaSolicitud' => $now, 'Estado' => 'EN_PROCESO',
            'FechaInicio' => $now, 'Latido' => $now, 'VersionCalculo' => MonthlyReportBuilder::VERSION, 'Evidencia' => $evidence,
        ], 'IdCaptura');
    }

    /**
     * Days of $month proven by earlier finished captures, with the capture that first proved each one (chains of
     * CAPTURA are followed back to the original proof). @return array<int, array{id_captura:int, caidas: array<int, list<string>>}>
     */
    public function provenDays(string $month, int $beforeId): array
    {
        $rows = $this->db->table('dbo.movdDispCapturaDia as d')->join('dbo.movdDispCaptura as c', 'c.IdCaptura', '=', 'd.IdCaptura')
            ->where('d.Mes', $month)->where('d.Cobertura', 'COMPLETA')->where('d.IdCaptura', '<', $beforeId)
            ->whereIn('c.Estado', self::FINAL)->whereNotNull('c.HashContenido')
            ->orderByDesc('d.IdCaptura')->get(['d.IdCaptura', 'd.Dia', 'd.Fuente', 'd.IdCapturaOrigen']);
        $proven = [];
        foreach ($rows as $row) {
            $day = (int) $row->Dia;
            if (isset($proven[$day])) {
                continue; // the most recent capture that proved the day
            }
            $downs = [];
            foreach ($this->db->table('dbo.movdDispCapturaCaida')->where('IdCaptura', $row->IdCaptura)->where('Mes', $month)->where('Dia', $day)->get() as $down) {
                $downs[(int) $down->NodoOrigen] = explode(',', $down->Origenes);
            }
            $proven[$day] = ['id_captura' => (int) ($row->Fuente === 'CAPTURA' ? $row->IdCapturaOrigen : $row->IdCaptura), 'caidas' => $downs];
        }

        return $proven;
    }

    /**
     * Writes the whole capture and closes it in one transaction; selects it for its «Last Month» when it is COMPLETA,
     * valid and newer than the current selection.
     *
     * @param  array  $capture  see MonthlyCaptureRunner::collect()
     * @return array{estado: string, valida: bool, motivos: list<string>, hash: string, seleccionada: bool}
     */
    public function finish(int $id, array $capture): array
    {
        [$state, $valid, $reasons] = self::evaluate($capture);
        $labels = MonthlyReportBuilder::labels(array_map(fn ($n, $nid) => ['node_id' => $nid] + $n, $capture['nodos'], array_keys($capture['nodos'])));

        return $this->db->transaction(function () use ($id, $capture, $state, $valid, $reasons, $labels): array {
            $this->lock('SIALU:disp-mensual:cierre');
            $row = $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $id)->lockForUpdate()->first();
            if (! $row || $row->Estado !== 'EN_PROCESO') {
                throw new RuntimeException('La captura '.$id.' ya no está en proceso.');
            }
            $nodes = [];
            foreach ($capture['nodos'] as $nodeId => $n) {
                $nodes[] = [
                    'IdCaptura' => $id, 'NodoOrigen' => $nodeId, 'Nombre' => self::text($n['nombre']),
                    'Departamento' => $n['departamento'], 'Ciudad' => $n['ciudad'],
                    'Area' => $labels[$nodeId]['area'], 'Grupo' => $labels[$nodeId]['grupo'],
                    'Estado' => $n['estado'], 'Motivo' => $n['motivo'] === null ? null : mb_substr($n['motivo'], 0, 300),
                    'HttpEstado' => $n['http'], 'ConsultadoEn' => $n['consultado_en'], 'RespuestaOrion' => $n['respuesta'],
                    'TextoMesAnterior' => $n['texto_anterior'], 'DispMesAnterior' => self::decimal($n['milesimas_anterior']),
                    'TextoMesActual' => $n['texto_actual'], 'DispMesActual' => self::decimal($n['milesimas_actual']),
                ];
            }
            foreach (array_chunk($nodes, 100) as $chunk) {
                $this->db->table('dbo.movdDispCapturaNodo')->insert($chunk);
            }

            foreach (['anterior' => $capture['mes_anterior'], 'actual' => $capture['mes_actual']] as $key => $month) {
                $days = $capture['dias'][$month];
                $this->db->table('dbo.movdDispCapturaDia')->insert(array_map(fn ($day, $info) => [
                    'IdCaptura' => $id, 'Mes' => $month, 'Dia' => $day, 'Cobertura' => $info['cobertura'],
                    'Fuente' => $info['fuente'], 'IdCapturaOrigen' => $info['origen'] ?? null, 'Motivo' => $info['motivo'],
                ], array_keys($days['dias']), $days['dias']));
                $downs = [];
                foreach ($days['caidas'] as $day => $byNode) {
                    foreach ($byNode as $nodeId => $ids) {
                        if (isset($capture['nodos'][$nodeId]) && $ids !== []) {
                            $downs[] = ['IdCaptura' => $id, 'Mes' => $month, 'Dia' => $day, 'NodoOrigen' => $nodeId, 'Caidas' => count($ids), 'Origenes' => implode(',', $ids)];
                        }
                    }
                }
                foreach (array_chunk($downs, 300) as $chunk) {
                    $this->db->table('dbo.movdDispCapturaCaida')->insert($chunk);
                }
                $report = MonthlyReportBuilder::build($this->builderNodes($id, $key), $this->storedDays($id, $month), $this->storedDowns($id, $month));
                $summary = array_map(fn ($s) => [
                    'IdCaptura' => $id, 'Mes' => $month, 'Orden' => $s['orden'], 'Nivel' => $s['nivel'], 'Area' => $s['area'], 'Grupo' => $s['grupo'],
                    'TotalNodos' => $s['total_nodos'], 'Incluidos' => $s['incluidos'], 'SinDatos' => $s['sin_datos'], 'ConError' => $s['con_error'],
                    'NoConsultados' => $s['no_consultados'], 'SumaMilesimas' => $s['suma_milesimas'], 'Disponibilidad' => $s['disponibilidad'],
                    'CaidasPorDia' => json_encode($s['caidas_por_dia'], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                ], $report['resumen']);
                foreach (array_chunk($summary, 100) as $chunk) {
                    $this->db->table('dbo.movdDispCapturaResumen')->insert($chunk);
                }
            }

            $count = array_count_values(array_column($capture['nodos'], 'estado'));
            $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $id)->update([
                'MesAnterior' => $capture['mes_anterior'], 'MesActual' => $capture['mes_actual'],
                'RelojOrionUtcInicio' => $capture['reloj_inicio']['utc'], 'RelojOrionLocalInicio' => $capture['reloj_inicio']['local'],
                'RelojOrionUtcFin' => $capture['reloj_fin']['utc'] ?? null, 'RelojOrionLocalFin' => $capture['reloj_fin']['local'] ?? null,
                'TotalNodos' => count($capture['nodos']), 'NodosConsultados' => count($capture['nodos']) - ($count['NO_CONSULTADO'] ?? 0),
                'NodosOk' => $count['OK'] ?? 0, 'NodosSinDatos' => $count['SIN_DATOS'] ?? 0, 'NodosError' => $count['ERROR'] ?? 0,
                'NodosNoConsultados' => $count['NO_CONSULTADO'] ?? 0,
                'InventarioEstable' => $capture['inventario_estable'], 'MesEstable' => $capture['mes_estable'],
                'SesionCerrada' => $capture['sesion_cerrada'], 'Advertencias' => $capture['advertencias'] === [] ? null : json_encode($capture['advertencias'], JSON_UNESCAPED_UNICODE),
                'Evidencia' => $capture['evidencia'] ?? $row->Evidencia, 'MensajeError' => $capture['error'] === null ? null : mb_substr($capture['error'], 0, 500),
            ]);
            $hash = hash('sha256', $this->canonical($id));
            $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $id)->update([
                'Estado' => $state, 'Valida' => $valid, 'MotivosInvalidez' => $reasons === [] ? null : json_encode($reasons, JSON_UNESCAPED_UNICODE),
                'HashContenido' => $hash, 'FechaFin' => self::now(), 'Latido' => self::now(),
            ]);

            $selected = false;
            if ($valid) {
                $current = $this->selection($capture['mes_anterior']);
                $currentStart = $current === null ? null : $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $current['id_captura'])->value('RelojOrionUtcInicio');
                if ($current === null || CarbonImmutable::parse($currentStart, 'UTC')->lt(CarbonImmutable::parse($capture['reloj_inicio']['utc'], 'UTC'))) {
                    $this->db->table('dbo.movdDispSeleccionMes')->insert([
                        'Mes' => $capture['mes_anterior'], 'IdCaptura' => $id, 'IdCapturaAnterior' => $current['id_captura'] ?? null,
                        'FechaSeleccion' => self::now(), 'Motivo' => 'Última captura completa y validada obtenida durante el mes siguiente.',
                    ]);
                    $selected = true;
                }
            }

            return ['estado' => $state, 'valida' => $valid, 'motivos' => $reasons, 'hash' => $hash, 'seleccionada' => $selected];
        });
    }

    /**
     * COMPLETA when every node ended OK or SIN_DATOS; PARCIAL when some did not but at least one did; FALLIDA otherwise.
     * Valid only when COMPLETA, the inventory and Orion's local month did not change during the capture, and Orion's
     * clock confirmed the UTC-6 offset at both ends.
     *
     * @return array{0: string, 1: bool, 2: list<string>}
     */
    public static function evaluate(array $capture): array
    {
        $count = array_count_values(array_column($capture['nodos'], 'estado'));
        $done = ($count['OK'] ?? 0) + ($count['SIN_DATOS'] ?? 0);
        $state = match (true) {
            $capture['nodos'] !== [] && $done === count($capture['nodos']) => 'COMPLETA',
            $done > 0 => 'PARCIAL',
            default => 'FALLIDA',
        };
        $reasons = [];
        if ($state !== 'COMPLETA') {
            $reasons[] = 'Captura '.strtolower($state).': '.(($count['ERROR'] ?? 0) + ($count['NO_CONSULTADO'] ?? 0)).' nodos con error o sin consultar.';
        }
        if ($capture['error'] !== null) {
            $reasons[] = 'Error durante la captura: '.$capture['error'];
        }
        if ($capture['inventario_estable'] !== true) {
            $reasons[] = 'El inventario de Orion.Nodes cambió o no se pudo comprobar al final.';
        }
        if ($capture['mes_estable'] !== true) {
            $reasons[] = 'El mes local de Orion cambió durante la captura o no se pudo comprobar.';
        }
        foreach (['reloj_inicio', 'reloj_fin'] as $clock) {
            $c = $capture[$clock] ?? null;
            $offset = $c === null ? null : CarbonImmutable::parse($c['utc'], 'UTC')->diffInMinutes(CarbonImmutable::parse($c['local'], 'UTC'), false);
            if ($offset === null || abs($offset - DowntimeDays::OFFSET_HOURS * 60) > 1) {
                $reasons[] = 'El reloj de Orion no confirmó la hora local UTC-6 ('.$clock.').';
            }
        }

        return [$state, $state === 'COMPLETA' && $reasons === [], $reasons];
    }

    /** @return ?array{id_captura:int, fecha:string, id_seleccion:int} */
    public function selection(string $month): ?array
    {
        $row = $this->db->table('dbo.movdDispSeleccionMes')->where('Mes', $month)->orderByDesc('IdSeleccion')->first();

        return $row === null ? null : ['id_captura' => (int) $row->IdCaptura, 'fecha' => self::iso($row->FechaSeleccion), 'id_seleccion' => (int) $row->IdSeleccion];
    }

    public function selections(string $month): array
    {
        return $this->db->table('dbo.movdDispSeleccionMes')->where('Mes', $month)->orderBy('IdSeleccion')->get()
            ->map(fn ($r) => ['id_seleccion' => (int) $r->IdSeleccion, 'id_captura' => (int) $r->IdCaptura,
                'id_captura_anterior' => $r->IdCapturaAnterior === null ? null : (int) $r->IdCapturaAnterior,
                'fecha' => self::iso($r->FechaSeleccion), 'motivo' => $r->Motivo])->all();
    }

    public function find(int $id): ?object
    {
        return $this->db->table('dbo.movdDispCaptura')->where('IdCaptura', $id)->first();
    }

    /** Captures that contain $month, newest first. */
    public function forMonth(string $month): array
    {
        return $this->db->table('dbo.movdDispCaptura')->where(fn ($q) => $q->where('MesAnterior', $month)->orWhere('MesActual', $month))
            ->orderByDesc('IdCaptura')->get()->all();
    }

    /** Latest finished capture whose «This Month» is $month (provisional report of the current month). */
    public function latestCurrent(string $month): ?object
    {
        return $this->db->table('dbo.movdDispCaptura')->where('MesActual', $month)->whereIn('Estado', ['COMPLETA', 'PARCIAL'])
            ->orderByDesc('IdCaptura')->first();
    }

    /** Latest finished, not selectable capture whose «Last Month» is $month. */
    public function latestUnselectable(string $month): ?object
    {
        return $this->db->table('dbo.movdDispCaptura')->where('MesAnterior', $month)->whereIn('Estado', ['COMPLETA', 'PARCIAL'])
            ->orderByDesc('IdCaptura')->first();
    }

    /**
     * Report rows of one month of one capture, exactly as stored.
     *
     * @return array{nodos: list<array>, resumen: list<array>, dias: array<int, array>}
     */
    public function stored(int $id, string $month): array
    {
        $capture = $this->find($id);
        $key = $capture->MesAnterior === $month ? 'anterior' : 'actual';
        $days = $this->storedDays($id, $month);
        $downs = $this->storedDowns($id, $month);
        $nodes = [];
        foreach ($this->builderNodes($id, $key) as $n) {
            $cells = [];
            foreach ($days as $day => $info) {
                $cells[$day] = $info['cobertura'] === 'COMPLETA' ? count($downs[$day][$n['node_id']] ?? []) : null;
            }
            $nodes[] = $n + ['dias' => $cells];
        }
        $summary = $this->db->table('dbo.movdDispCapturaResumen')->where('IdCaptura', $id)->where('Mes', $month)->orderBy('Orden')->get()
            ->map(fn ($r) => ['orden' => (int) $r->Orden, 'nivel' => $r->Nivel, 'area' => $r->Area, 'grupo' => $r->Grupo,
                'total_nodos' => (int) $r->TotalNodos, 'incluidos' => (int) $r->Incluidos, 'sin_datos' => (int) $r->SinDatos,
                'con_error' => (int) $r->ConError, 'no_consultados' => (int) $r->NoConsultados, 'suma_milesimas' => (int) $r->SumaMilesimas,
                'disponibilidad' => self::fixed($r->Disponibilidad),
                'caidas_por_dia' => array_map(fn ($v) => $v === null ? null : (int) $v, json_decode($r->CaidasPorDia, true, 4, JSON_THROW_ON_ERROR))])->all();

        return ['nodos' => $nodes, 'resumen' => $summary, 'dias' => $days];
    }

    /** Canonical text of everything a report shows, in a fixed order and format; its SHA-256 is HashContenido. */
    public function canonical(int $id): string
    {
        $c = $this->find($id);
        $out = ['captura' => $id, 'version' => $c->VersionCalculo, 'mes_anterior' => $c->MesAnterior, 'mes_actual' => $c->MesActual, 'meses' => []];
        $out['nodos'] = $this->db->table('dbo.movdDispCapturaNodo')->where('IdCaptura', $id)->orderBy('NodoOrigen')->get()
            ->map(fn ($n) => [(int) $n->NodoOrigen, $n->Nombre, $n->Departamento, $n->Ciudad, $n->Area, $n->Grupo, $n->Estado,
                $n->TextoMesAnterior, self::fixed($n->DispMesAnterior), $n->TextoMesActual, self::fixed($n->DispMesActual), $n->RespuestaOrion])->all();
        foreach (array_filter([$c->MesAnterior, $c->MesActual]) as $month) {
            $out['meses'][$month] = [
                'dias' => $this->db->table('dbo.movdDispCapturaDia')->where('IdCaptura', $id)->where('Mes', $month)->orderBy('Dia')->get()
                    ->map(fn ($d) => [(int) $d->Dia, $d->Cobertura, $d->Fuente, $d->IdCapturaOrigen === null ? null : (int) $d->IdCapturaOrigen])->all(),
                'caidas' => $this->db->table('dbo.movdDispCapturaCaida')->where('IdCaptura', $id)->where('Mes', $month)->orderBy('Dia')->orderBy('NodoOrigen')->get()
                    ->map(fn ($d) => [(int) $d->Dia, (int) $d->NodoOrigen, (int) $d->Caidas, $d->Origenes])->all(),
                'resumen' => array_map(fn ($s) => array_values($s), $this->stored($id, $month)['resumen']),
            ];
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function verify(int $id): bool
    {
        $hash = $this->find($id)?->HashContenido;

        return $hash !== null && hash_equals($hash, hash('sha256', $this->canonical($id)));
    }

    private function builderNodes(int $id, string $key): array
    {
        $column = $key === 'anterior' ? 'DispMesAnterior' : 'DispMesActual';
        $text = $key === 'anterior' ? 'TextoMesAnterior' : 'TextoMesActual';

        return $this->db->table('dbo.movdDispCapturaNodo')->where('IdCaptura', $id)->orderBy('NodoOrigen')->get()
            ->map(fn ($n) => ['node_id' => (int) $n->NodoOrigen, 'nombre' => $n->Nombre, 'area' => $n->Area, 'grupo' => $n->Grupo,
                'estado' => $n->Estado, 'motivo' => $n->Motivo, 'texto_orion' => $n->$text,
                'milesimas' => $n->$column === null ? null : self::thousandths($n->$column)])->all();
    }

    /** @return array<int, array{cobertura:string, fuente:?string, origen:?int, motivo:?string}> */
    private function storedDays(int $id, string $month): array
    {
        $days = [];
        foreach ($this->db->table('dbo.movdDispCapturaDia')->where('IdCaptura', $id)->where('Mes', $month)->orderBy('Dia')->get() as $d) {
            $days[(int) $d->Dia] = ['cobertura' => $d->Cobertura, 'fuente' => $d->Fuente, 'origen' => $d->IdCapturaOrigen === null ? null : (int) $d->IdCapturaOrigen, 'motivo' => $d->Motivo];
        }

        return $days;
    }

    private function storedDowns(int $id, string $month): array
    {
        $downs = [];
        foreach ($this->db->table('dbo.movdDispCapturaCaida')->where('IdCaptura', $id)->where('Mes', $month)->get() as $d) {
            $downs[(int) $d->Dia][(int) $d->NodoOrigen] = explode(',', $d->Origenes);
        }

        return $downs;
    }

    private function lock(string $resource): void
    {
        $lock = $this->db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", [$resource]);
        if ((int) $lock->result < 0) {
            throw new RuntimeException('No se obtuvo el bloqueo '.$resource.'.');
        }
    }

    /** decimal(6,3) as returned by the driver ("23.719", "23.7190", 23.719) → exact thousandths. */
    public static function thousandths(mixed $value): int
    {
        $text = is_string($value) ? $value : number_format((float) $value, 3, '.', '');
        // pdo_sqlsrv returns values below 1 without the leading zero (".500").
        if (! preg_match('/^(\d{0,3})(?:\.(\d{1,4}))?$/', trim($text), $m) || trim($text) === '' || strlen(rtrim($m[2] ?? '', '0')) > 3) {
            throw new RuntimeException('Porcentaje almacenado no reconocido.');
        }

        return (int) $m[1] * 1000 + (int) substr(str_pad($m[2] ?? '', 3, '0'), 0, 3);
    }

    private static function fixed(mixed $value): ?string
    {
        return $value === null ? null : MonthlyReportBuilder::format(self::thousandths($value));
    }

    private static function decimal(?int $thousandths): ?string
    {
        return $thousandths === null ? null : MonthlyReportBuilder::format($thousandths);
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    public static function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');
    }

    public static function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toIso8601ZuluString('millisecond');
    }
}
