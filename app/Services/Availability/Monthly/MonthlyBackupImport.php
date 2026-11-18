<?php

namespace App\Services\Availability\Monthly;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Turns a verified evidence backup (storage/app/resguardos/orion/...) into capture data with the same rules as a
 * live capture. Nothing is trusted before every file matches SHA256SUMS. The backup only holds the DOWN/UP events of
 * its «Last Month», so the days of its «This Month» stay s/d (or keep a proof from an earlier capture).
 */
final class MonthlyBackupImport
{
    private const FILES = ['manifest.json', 'nodos-inicio.json', 'nodos-final.json', 'availability-statistics.json',
        'eventos-down-up-octubre.json', 'cobertura-eventos-octubre.json'];

    /** @return array<string, string> file => sha256, after checking every listed file */
    public static function verify(string $dir): array
    {
        $sums = @file_get_contents($dir.'/SHA256SUMS');
        if ($sums === false) {
            throw new RuntimeException('El resguardo no tiene SHA256SUMS.');
        }
        $hashes = [];
        foreach (preg_split('/\R/', trim($sums)) as $line) {
            if (! preg_match('/^([0-9a-f]{64})\s+(\S+)$/', $line, $m) || str_contains($m[2], '/') || str_contains($m[2], '\\')) {
                throw new RuntimeException('Línea de SHA256SUMS no reconocida.');
            }
            $content = @file_get_contents($dir.'/'.$m[2]);
            if ($content === false || ! hash_equals($m[1], hash('sha256', $content))) {
                throw new RuntimeException('El archivo '.$m[2].' no coincide con su hash.');
            }
            $hashes[$m[2]] = $m[1];
        }
        foreach (self::FILES as $file) {
            if (! isset($hashes[$file])) {
                throw new RuntimeException('Falta '.$file.' en SHA256SUMS.');
            }
        }

        return $hashes;
    }

    public static function load(string $dir, MonthlyCaptureRepository $repository, int $captureId): array
    {
        $hashes = self::verify($dir);
        $read = fn (string $file) => json_decode(file_get_contents($dir.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
        $manifest = $read('manifest.json');
        $start = $read('nodos-inicio.json');
        $end = $read('nodos-final.json');
        $stats = $read('availability-statistics.json');
        $events = $read('eventos-down-up-octubre.json');
        $coverage = $read('cobertura-eventos-octubre.json');

        $clock = fn (array $c) => ['utc' => CarbonImmutable::parse($c['Utc'], 'UTC')->format('Y-m-d H:i:s.v'), 'local' => CarbonImmutable::parse($c['Local'], 'UTC')->format('Y-m-d H:i:s.v')];
        $clockStart = $clock($manifest['reloj_orion_inicio']);
        $clockEnd = $clock($manifest['reloj_orion_final']);
        $current = substr($clockStart['local'], 0, 7);
        $previous = MonthlyCaptureRunner::previousMonth($current);
        if ($previous !== '2026-10') {
            throw new RuntimeException('Este importador solo reconoce el resguardo de octubre de 2026.');
        }

        $inventory = fn (array $rows) => array_combine(array_map(fn ($n) => (int) $n['NodeID'], $rows),
            array_map(fn ($n) => ['nombre' => $n['Caption'] ?? null, 'departamento' => $n['Department'] ?? null, 'ciudad' => $n['City'] ?? null], $rows));
        $startInv = $inventory($start);
        $endInv = $inventory($end);

        $nodes = [];
        $byNode = array_column($stats, null, 'node_id');
        foreach ($startInv as $id => $node) {
            $s = $byNode[$id] ?? null;
            if ($s === null || ($s['estado'] ?? null) === 'no_consultado' || ! array_key_exists('cuerpo', $s)) {
                $nodes[$id] = $node + ['estado' => 'NO_CONSULTADO', 'motivo' => 'El resguardo no consultó el nodo.', 'http' => null, 'consultado_en' => null,
                    'respuesta' => null, 'texto_anterior' => null, 'milesimas_anterior' => null, 'texto_actual' => null, 'milesimas_actual' => null];

                continue;
            }
            $at = $s['recibida'] === null ? null : CarbonImmutable::parse($s['recibida'])->utc()->format('Y-m-d H:i:s.v');
            $entry = MonthlyCaptureRunner::classify($s['http'], $s['cuerpo'], $at) + $node;
            if ($s['error'] !== null) {
                $entry = ['estado' => 'ERROR', 'motivo' => $s['error']] + $entry;
            }
            if ($entry['estado'] === 'VACIA') {
                $entry['estado'] = isset($endInv[$id]) ? 'SIN_DATOS' : 'ERROR';
                $entry['motivo'] = isset($endInv[$id]) ? 'Orion no devolvió periodos; el NodeID existe en Orion.Nodes al inicio y al final.'
                    : 'Respuesta vacía y el NodeID ya no existe en Orion.Nodes.';
            }
            $nodes[$id] = $entry;
        }

        $readAt = null;
        foreach ($manifest['consultas'] as $q) {
            if ($q['nombre'] === 'eventos_down_up_octubre') {
                $readAt = CarbonImmutable::parse($q['enviada'])->utc();
            }
        }
        $oldest = CarbonImmutable::parse($coverage['limite_utc'])->utc();
        $days = [
            $previous => DowntimeDays::forMonth($previous, $events, $oldest, $readAt, null, 60, $repository->provenDays($previous, $captureId)),
            $current => DowntimeDays::forMonth($current, [], null, $readAt, null, 60, $repository->provenDays($current, $captureId)),
        ];
        foreach ($days[$current]['dias'] as $n => $day) {
            if ($day['cobertura'] === 'SD') {
                $days[$current]['dias'][$n]['motivo'] = 'El resguardo no incluye eventos de este mes.';
            }
        }

        ksort($startInv);
        ksort($endInv);

        return [
            'reloj_inicio' => $clockStart, 'reloj_fin' => $clockEnd, 'mes_anterior' => $previous, 'mes_actual' => $current,
            'mes_estable' => substr($clockEnd['local'], 0, 7) === $current,
            'inventario_estable' => $startInv === $endInv,
            'nodos' => $nodes, 'dias' => $days,
            'sesion_cerrada' => (bool) (end($manifest['sesiones_web'])['sesion_cerrada'] ?? false),
            'advertencias' => array_merge(
                ['Importada del resguardo de evidencia; manifest.json sha256 '.$hashes['manifest.json'].'.'],
                array_map(fn ($w) => "$previous: $w", $days[$previous]['advertencias'])),
            'error' => null, 'evidencia' => mb_substr($dir, 0, 400),
        ];
    }
}
