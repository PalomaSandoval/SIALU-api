<?php

namespace App\Services\Availability\Monthly;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The monthly report of one month, read only from stored captures (never from Orion).
 *
 * estado_mes:
 * - oficial: closed month whose capture selection can no longer change (the following month ended);
 * - vigente: closed month with a selected (complete and valid) capture that a later valid capture may still replace;
 * - provisional: current month, from «This Month» of the latest finished capture;
 * - parcial / no_validada: closed month with captures, none complete and valid;
 * - no_seleccionada: a specific capture that is not the month's selection;
 * - no_disponible: no capture of the month.
 * `marca_parcial` is true whenever the capture shown is PARCIAL.
 */
class MonthlyReportService
{
    public function __construct(private MonthlyCaptureRepository $repository) {}

    public function report(string $month, ?int $captureId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $current = DowntimeDays::localMonth($now);
        if ($month > $current) {
            throw new InvalidArgumentException('El mes todavía no comienza.');
        }
        $selection = $this->repository->selection($month);

        if ($captureId !== null) {
            $capture = $this->repository->find($captureId);
            if ($capture === null || ! in_array($month, [$capture->MesAnterior, $capture->MesActual], true) || ! in_array($capture->Estado, MonthlyCaptureRepository::FINAL, true)) {
                throw new InvalidArgumentException('La captura no existe, no ha terminado o no contiene ese mes.');
            }
            if ($capture->Estado === 'FALLIDA') {
                throw new InvalidArgumentException('La captura falló y no contiene un reporte.');
            }
            $state = match (true) {
                $capture->MesAnterior === $month && $selection !== null && $selection['id_captura'] === $captureId => $this->closedState($month, $current),
                $capture->MesActual === $month && $month === $current => 'provisional',
                default => 'no_seleccionada',
            };

            return $this->build($month, $capture, $state, $selection);
        }

        if ($month === $current) {
            $capture = $this->repository->latestCurrent($month);

            return $capture === null ? $this->unavailable($month) : $this->build($month, $capture, 'provisional', $selection);
        }
        if ($selection !== null) {
            return $this->build($month, $this->repository->find($selection['id_captura']), $this->closedState($month, $current), $selection);
        }
        $capture = $this->repository->latestUnselectable($month);
        if ($capture !== null) {
            return $this->build($month, $capture, $capture->Estado === 'PARCIAL' ? 'parcial' : 'no_validada', null);
        }

        return $this->unavailable($month);
    }

    /** A closed month is official once the month in which it could be captured has ended. */
    private function closedState(string $month, string $current): string
    {
        $window = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC')->addMonthNoOverflow()->format('Y-m');

        return $current > $window ? 'oficial' : 'vigente';
    }

    private function unavailable(string $month): array
    {
        return ['mes' => $month, 'dias_mes' => count(DowntimeDays::days($month)), 'estado_mes' => 'no_disponible', 'marca_parcial' => false,
            'captura' => null, 'dias' => [], 'resumen' => [], 'nodos' => [],
            'capturas' => $this->captures($month), 'selecciones' => $this->repository->selections($month)];
    }

    private function build(string $month, object $capture, string $state, ?array $selection): array
    {
        $id = (int) $capture->IdCaptura;
        $stored = $this->repository->stored($id, $month);
        $days = [];
        foreach ($stored['dias'] as $day => $info) {
            $days[] = ['dia' => $day, 'cobertura' => $info['cobertura'], 'fuente' => $info['fuente'], 'captura_origen' => $info['origen'], 'motivo' => $info['motivo']];
        }

        return [
            'mes' => $month, 'dias_mes' => count($stored['dias']), 'estado_mes' => $state, 'marca_parcial' => $capture->Estado === 'PARCIAL',
            'captura' => [
                'id' => $id, 'origen' => $capture->Origen, 'estado' => $capture->Estado, 'valida' => (bool) $capture->Valida,
                'periodo_orion' => $capture->MesAnterior === $month ? 'Last Month' : 'This Month',
                'inicio' => MonthlyCaptureRepository::iso($capture->FechaInicio), 'fin' => MonthlyCaptureRepository::iso($capture->FechaFin),
                'reloj_orion_local' => $capture->RelojOrionLocalInicio === null ? null : CarbonImmutable::parse($capture->RelojOrionLocalInicio, 'UTC')->format('Y-m-d H:i:s'),
                'motivos_invalidez' => $capture->MotivosInvalidez === null ? [] : json_decode($capture->MotivosInvalidez, true),
                'advertencias' => $capture->Advertencias === null ? [] : json_decode($capture->Advertencias, true),
                'sesion_cerrada' => $capture->SesionCerrada === null ? null : (bool) $capture->SesionCerrada,
                'hash' => $capture->HashContenido, 'integridad_verificada' => $this->repository->verify($id),
                'seleccionada' => $selection !== null && $selection['id_captura'] === $id && $capture->MesAnterior === $month,
                'seleccion' => $selection !== null && $selection['id_captura'] === $id ? $selection : null,
                'conteos' => ['total' => (int) $capture->TotalNodos, 'ok' => (int) $capture->NodosOk, 'sin_datos' => (int) $capture->NodosSinDatos,
                    'error' => (int) $capture->NodosError, 'no_consultados' => (int) $capture->NodosNoConsultados],
            ],
            'dias' => $days,
            'resumen' => $stored['resumen'],
            'nodos' => array_map(fn ($n) => [
                'node_id' => $n['node_id'], 'nombre' => $n['nombre'], 'area' => $n['area'], 'grupo' => $n['grupo'], 'estado' => strtolower($n['estado']),
                'motivo' => $n['motivo'], 'texto_orion' => $n['texto_orion'],
                'disponibilidad' => $n['milesimas'] === null ? null : MonthlyReportBuilder::format($n['milesimas']), 'dias' => $n['dias'],
            ], $this->ordered($stored['nodos'])),
            'capturas' => $this->captures($month), 'selecciones' => $this->repository->selections($month),
        ];
    }

    /** Same order as the stored summary rows: area, group, node name, NodeID; null labels last. */
    private function ordered(array $nodes): array
    {
        usort($nodes, fn ($a, $b) => \App\Services\Availability\AreaAvailabilityCalculator::compareLabels($a['area'], $b['area'])
            ?: \App\Services\Availability\AreaAvailabilityCalculator::compareLabels($a['grupo'], $b['grupo'])
            ?: \App\Services\Availability\AreaAvailabilityCalculator::compareLabels($a['nombre'], $b['nombre'])
            ?: $a['node_id'] <=> $b['node_id']);

        return $nodes;
    }

    public function captures(string $month): array
    {
        return array_map(fn ($c) => [
            'id' => (int) $c->IdCaptura, 'origen' => $c->Origen, 'estado' => $c->Estado, 'valida' => (bool) $c->Valida,
            'solicitada' => MonthlyCaptureRepository::iso($c->FechaSolicitud), 'inicio' => MonthlyCaptureRepository::iso($c->FechaInicio),
            'fin' => MonthlyCaptureRepository::iso($c->FechaFin), 'periodo_orion' => $c->MesAnterior === $month ? 'Last Month' : 'This Month',
        ], $this->repository->forMonth($month));
    }
}
