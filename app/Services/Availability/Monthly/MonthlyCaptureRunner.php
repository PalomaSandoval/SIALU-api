<?php

namespace App\Services\Availability\Monthly;

use App\Integrations\Orion\OrionAvailabilityStatsParser as Parser;
use App\Integrations\Orion\OrionClient;
use App\Integrations\Orion\OrionWebClient;
use App\Integrations\Orion\OrionWebException;
use App\Services\Availability\OrionEventsRead;
use App\Services\Ingestion\SourceIdentity;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Collects one monthly capture from Orion, read only:
 * 1. Orion clock and Orion.Nodes (inventory and classification) through SWIS;
 * 2. «Availability Statistics» of every node, sequentially, in ONE web session (pause between nodes, one retry of
 *    the failed nodes at the end, one re-login if the session expires, abort after consecutive failures);
 * 3. Orion.Events DOWN/UP still retained and the verified SIALU read, for the day columns;
 * 4. Orion.Nodes and the clock again: an empty answer is «sin datos» only for a NodeID present at both ends.
 * Logout is always attempted. Any failure is recorded per node or for the capture; nothing is guessed.
 */
class MonthlyCaptureRunner
{
    public const PAUSE_MICROSECONDS = 200_000;

    public const MAX_CONSECUTIVE_ERRORS = 5;

    private const NODES_SQL = 'SELECT n.NodeID, n.Caption, n.CustomProperties.Department AS Department, '
        .'n.CustomProperties.City AS City FROM Orion.Nodes n ORDER BY n.NodeID';

    private const CLOCK_SQL = 'SELECT TOP 1 GETUTCDATE() AS Utc, GETDATE() AS Local FROM Orion.Nodes';

    private const OLDEST_SQL = 'SELECT MIN(e.EventTime) AS MinEventTime FROM Orion.Events e';

    public function __construct(
        private MonthlyCaptureRepository $repository,
        private ?Closure $swis = null,
        private ?Closure $web = null,
        private int $pause = self::PAUSE_MICROSECONDS,
    ) {
        $this->swis ??= fn (string $sql) => app(OrionClient::class)->query($sql);
        $this->web ??= fn () => new OrionWebClient;
    }

    /** Runs a claimed capture to its end. @return array result of MonthlyCaptureRepository::finish() */
    public function run(int $id): array
    {
        try {
            $capture = $this->collect(fn (int $done, int $total) => $this->repository->heartbeat($id, $done, $total), $id);
        } catch (Throwable $e) {
            $this->repository->fail($id, self::message($e));
            throw $e;
        }

        return $this->repository->finish($id, $capture);
    }

    /** @return array capture data for MonthlyCaptureRepository::finish() */
    public function collect(Closure $progress, int $captureId): array
    {
        $clockStart = $this->clock();
        $inventory = $this->inventory();
        $oldestBefore = $this->oldest();
        // «This Month» and «Last Month» follow Orion's own local date.
        $currentMonth = substr($clockStart['local'], 0, 7);
        $previousMonth = self::previousMonth($currentMonth);

        $nodes = [];
        foreach ($inventory as $id => $node) {
            $nodes[$id] = $node + ['estado' => 'NO_CONSULTADO', 'motivo' => 'La captura terminó antes de consultar el nodo.', 'http' => null,
                'consultado_en' => null, 'respuesta' => null, 'texto_anterior' => null, 'milesimas_anterior' => null,
                'texto_actual' => null, 'milesimas_actual' => null];
        }
        $error = null;
        $closed = null;
        try {
            $session = ($this->web)()->session(function (OrionWebClient $client) use (&$nodes, $progress): void {
                $consecutive = 0;
                $done = 0;
                foreach (array_keys($nodes) as $id) {
                    $nodes[$id] = $this->ask($client, $id, $nodes[$id]);
                    $consecutive = $nodes[$id]['estado'] === 'ERROR' ? $consecutive + 1 : 0;
                    $progress(++$done, count($nodes));
                    if ($consecutive >= self::MAX_CONSECUTIVE_ERRORS) {
                        throw new OrionWebException(self::MAX_CONSECUTIVE_ERRORS.' nodos seguidos fallaron; se detuvo la captura.');
                    }
                    usleep($this->pause);
                }
                foreach ($nodes as $id => $node) {
                    if ($node['estado'] === 'ERROR') {
                        $nodes[$id] = $this->ask($client, $id, $node, 'Segundo intento. ');
                        usleep($this->pause);
                    }
                }
            });
            $closed = $session['sesion_cerrada'];
        } catch (Throwable $e) {
            $error = self::message($e);
        }

        // End of capture: inventory, clock and events. A failure here leaves sin_datos unproven and days as s/d.
        $endInventory = null;
        $clockEnd = null;
        $events = [];
        $oldestAfter = null;
        $readAt = null;
        try {
            $endInventory = $this->inventory();
            $clockEnd = $this->clock();
            $readAt = CarbonImmutable::now('UTC');
            $events = ($this->swis)("SELECT e.EventID, e.EventTime, e.EventType, e.NetworkNode FROM Orion.Events e WHERE e.EventType IN (1, 5) "
                ."AND e.NetObjectType = 'N' ORDER BY e.EventTime, e.EventID");
            $oldestAfter = $this->oldest();
        } catch (Throwable $e) {
            $error ??= 'Lectura final de Orion: '.self::message($e);
            $events = [];
        }
        foreach ($nodes as $id => $node) {
            if ($node['estado'] === 'VACIA') {
                $exists = $endInventory !== null && isset($endInventory[$id]);
                $nodes[$id]['estado'] = $exists ? 'SIN_DATOS' : 'ERROR';
                $nodes[$id]['motivo'] = $exists ? 'Orion no devolvió periodos; el NodeID existe en Orion.Nodes al inicio y al final.'
                    : ($endInventory === null ? 'Respuesta vacía y no se pudo confirmar que el NodeID exista.' : 'Respuesta vacía y el NodeID ya no existe en Orion.Nodes.');
            }
        }

        $oldest = $oldestBefore === null || $oldestAfter === null ? null : max($oldestBefore, $oldestAfter);
        $sialu = $this->sialu([$previousMonth, $currentMonth]);
        $days = [];
        $warnings = [];
        foreach ([$previousMonth, $currentMonth] as $month) {
            $days[$month] = $readAt === null
                ? DowntimeDays::forMonth($month, [], null, CarbonImmutable::now('UTC'), null, 0, $this->repository->provenDays($month, $captureId))
                : DowntimeDays::forMonth($month, $events, $oldest, $readAt, $sialu === null ? null : ['intervalos' => $sialu['intervalos'][$month]] + $sialu,
                    (int) config('sialu.coverage.late_margin_minutes', 60), $this->repository->provenDays($month, $captureId));
            $warnings = array_merge($warnings, array_map(fn ($w) => "$month: $w", $days[$month]['advertencias']));
        }

        return [
            'reloj_inicio' => $clockStart, 'reloj_fin' => $clockEnd,
            'mes_anterior' => $previousMonth, 'mes_actual' => $currentMonth,
            'mes_estable' => $clockEnd !== null && DowntimeDays::localMonth(CarbonImmutable::parse($clockEnd['utc'], 'UTC')) === $currentMonth
                && substr($clockStart['local'], 0, 7) === $currentMonth && substr($clockEnd['local'], 0, 7) === $currentMonth,
            'inventario_estable' => $endInventory !== null && self::sameInventory($inventory, $endInventory),
            'nodos' => $nodes, 'dias' => $days, 'sesion_cerrada' => $closed, 'advertencias' => $warnings, 'error' => $error, 'evidencia' => null,
        ];
    }

    /** One node: OK, VACIA (resolved later against Orion.Nodes) or ERROR, with the original body kept. */
    private function ask(OrionWebClient $client, int $id, array $node, string $prefix = ''): array
    {
        $at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v');
        try {
            $response = $client->fetchStats($id);
            if ($response['sesion_vencida']) {
                $client->relogin();
                $response = $client->fetchStats($id);
            }
        } catch (OrionWebException $e) {
            return ['estado' => 'ERROR', 'motivo' => $prefix.$e->getMessage(), 'http' => null, 'consultado_en' => $at, 'respuesta' => null] + $node;
        }

        return self::classify($response['status'], $response['body'], $at, $prefix) + $node;
    }

    /** Classifies one GetAvailabilityStats answer; shared with the evidence import. */
    public static function classify(?int $status, ?string $body, ?string $at, string $prefix = ''): array
    {
        $base = ['http' => $status, 'consultado_en' => $at, 'respuesta' => $body, 'texto_anterior' => null, 'milesimas_anterior' => null,
            'texto_actual' => null, 'milesimas_actual' => null];
        if ($status !== 200 || $body === null) {
            return ['estado' => 'ERROR', 'motivo' => $prefix.'GetAvailabilityStats respondió HTTP '.($status ?? 'sin respuesta').'.'] + $base;
        }
        try {
            $periods = Parser::parseOrEmpty($body);
            if ($periods === null) {
                return ['estado' => 'VACIA', 'motivo' => null] + $base;
            }
            $byPeriod = array_column($periods, 'texto', 'periodo');

            return ['estado' => 'OK', 'motivo' => null,
                'texto_anterior' => $byPeriod['Last Month'], 'milesimas_anterior' => Parser::thousandths($byPeriod['Last Month']),
                'texto_actual' => $byPeriod['This Month'], 'milesimas_actual' => Parser::thousandths($byPeriod['This Month'])] + $base;
        } catch (OrionWebException $e) {
            return ['estado' => 'ERROR', 'motivo' => $prefix.$e->getMessage()] + $base;
        }
    }

    /** @return array{utc: string, local: string} */
    private function clock(): array
    {
        $row = ($this->swis)(self::CLOCK_SQL)[0] ?? null;
        if (! isset($row['Utc'], $row['Local'])) {
            throw new RuntimeException('Orion no devolvió su reloj.');
        }

        return ['utc' => CarbonImmutable::parse($row['Utc'], 'UTC')->format('Y-m-d H:i:s.v'), 'local' => CarbonImmutable::parse($row['Local'], 'UTC')->format('Y-m-d H:i:s.v')];
    }

    /** @return array<int, array{nombre: ?string, departamento: ?string, ciudad: ?string}> */
    private function inventory(): array
    {
        $nodes = [];
        foreach (($this->swis)(self::NODES_SQL) as $row) {
            $nodes[(int) $row['NodeID']] = ['nombre' => $row['Caption'] ?? null, 'departamento' => $row['Department'] ?? null, 'ciudad' => $row['City'] ?? null];
        }
        if ($nodes === []) {
            throw new RuntimeException('Orion.Nodes no devolvió nodos.');
        }

        return $nodes;
    }

    private function oldest(): ?CarbonImmutable
    {
        $value = ($this->swis)(self::OLDEST_SQL)[0]['MinEventTime'] ?? null;

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /** Verified SIALU read and its DOWN intervals per month; null when SIALU cannot prove anything. */
    private function sialu(array $months): ?array
    {
        try {
            $sourceId = SourceIdentity::id('orion');
            $db = $this->repository->db();
            $read = OrionEventsRead::evaluate($db, $sourceId, CarbonImmutable::now('UTC'));
            if ($read['desde'] === null || $read['hasta'] === null) {
                return null;
            }
            $intervals = [];
            foreach ($months as $month) {
                $days = DowntimeDays::days($month);
                $intervals[$month] = $db->table('dbo.movdDisponibilidad')->where('IdFuente', $sourceId)->where('TipoObjeto', 'NODO')
                    ->where('FechaDown', '>=', $days[0]['inicio']->format('Y-m-d H:i:s.v'))
                    ->where('FechaDown', '<', end($days)['fin']->format('Y-m-d H:i:s.v'))
                    ->orderBy('FechaDown')->get(['IdDisponibilidad', 'ObjetoExterno', 'FechaDown'])
                    ->map(fn ($r) => ['id' => $r->IdDisponibilidad, 'node_id' => $r->ObjetoExterno, 'down' => $r->FechaDown])->all();
            }

            return ['desde' => $read['desde'], 'hasta' => $read['hasta'], 'intervalos' => $intervals];
        } catch (Throwable) {
            return null;
        }
    }

    public static function previousMonth(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC')->subMonthNoOverflow()->format('Y-m');
    }

    private static function sameInventory(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    private static function message(Throwable $e): string
    {
        // OrionWebException and RuntimeException messages are written by SIALU and carry no credentials.
        return $e instanceof OrionWebException || $e instanceof RuntimeException ? $e->getMessage() : class_basename($e);
    }
}
