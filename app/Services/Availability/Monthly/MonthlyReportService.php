<?php

namespace App\Services\Availability\Monthly;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The single report of one month, read only from stored captures (never from Orion). Which capture backs it is
 * decided here, not by the user: the first complete and validated capture selected for the closed month.
 * Later captures remain stored for audit and do not change that report.
 *
 * estado_mes:
 * - validado: closed month with a selected capture (complete and validated);
 * - pendiente: closed month without a selected capture while Orion still offers it; SIALU keeps retrying
 *   automatically (see MonthlyCapturePlanner) and the latest partial or not validated capture, if any, is shown marked;
 * - no_recuperable: the following month ended without a selected capture; Orion no longer offers the month;
 * - en_curso: the current month's provisional «This Month» capture, when available.
 * `automatico` carries the planner state (attempts, next attempt, window) for the explanation shown on screen.
 */
class MonthlyReportService
{
    public function __construct(private MonthlyCaptureRepository $repository, private ?MonthlyCapturePlanner $planner = null)
    {
        $this->planner ??= new MonthlyCapturePlanner($repository, MonthlyReportService::startAt());
    }

    public static function startAt(): string
    {
        $value = (string) config('sialu.schedule.orion_monthly.start_at', '02:00');

        return MonthlyCapturePlanner::validStartAt($value) ? $value : '02:00';
    }

    public function report(string $month, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        if ($month > DowntimeDays::localMonth($now)) {
            throw new InvalidArgumentException('El mes todavía no comienza.');
        }
        $auto = $this->planner->status($month, $now);
        if ($auto['estado'] === 'en_curso') {
            $capture = $this->repository->latestCurrent($month);

            return $capture === null ? $this->unavailable($month, 'en_curso', $auto) : $this->build($month, $capture, 'en_curso', $auto);
        }
        $selection = $this->repository->selection($month);
        if ($selection !== null) {
            return $this->build($month, $this->repository->find($selection['id_captura']), 'validado', $auto);
        }
        $state = $auto['estado'] === 'no_recuperable' ? 'no_recuperable' : 'pendiente';
        $capture = $this->repository->latestUnselectable($month);

        return $capture === null ? $this->unavailable($month, $state, $auto) : $this->build($month, $capture, $state, $auto);
    }

    private function unavailable(string $month, string $state, array $auto): array
    {
        return ['mes' => $month, 'dias_mes' => count(DowntimeDays::days($month)), 'estado_mes' => $state, 'marca_parcial' => false,
            'automatico' => self::publicAuto($auto), 'captura' => null, 'dias' => [], 'resumen' => [], 'nodos' => []];
    }

    /** What any user may see of the automatic process: no capture ids. */
    private static function publicAuto(array $auto): array
    {
        $last = $auto['ultimo_intento'];

        return ['estado' => $auto['estado'], 'ventana' => $auto['ventana'], 'intentos_fallidos' => $auto['intentos_fallidos'],
            'proximo_intento' => $auto['proximo_intento'], 'en_riesgo' => $auto['en_riesgo'],
            'ultimo_intento' => $last === null ? null : ['estado' => strtolower($last['estado']), 'fin' => $last['fin'], 'motivo' => $last['motivo']]];
    }

    private function build(string $month, object $capture, string $state, array $auto): array
    {
        $id = (int) $capture->IdCaptura;
        $stored = $this->repository->stored($id, $month);
        $days = [];
        foreach ($stored['dias'] as $day => $info) {
            $days[] = ['dia' => $day, 'cobertura' => $info['cobertura'], 'fuente' => $info['fuente'], 'captura_origen' => $info['origen'], 'motivo' => $info['motivo']];
        }

        return [
            'mes' => $month, 'dias_mes' => count($stored['dias']), 'estado_mes' => $state, 'marca_parcial' => $capture->Estado === 'PARCIAL',
            'automatico' => self::publicAuto($auto),
            'captura' => [
                'id' => $id, 'origen' => $capture->Origen, 'estado' => $capture->Estado, 'valida' => (bool) $capture->Valida,
                'periodo_orion' => $capture->MesAnterior === $month ? 'Last Month' : 'This Month',
                'inicio' => MonthlyCaptureRepository::iso($capture->FechaInicio), 'fin' => MonthlyCaptureRepository::iso($capture->FechaFin),
                'reloj_orion_local' => $capture->RelojOrionLocalInicio === null ? null : CarbonImmutable::parse($capture->RelojOrionLocalInicio, 'UTC')->format('Y-m-d H:i:s'),
                'motivos_invalidez' => $capture->MotivosInvalidez === null ? [] : json_decode($capture->MotivosInvalidez, true),
                'advertencias' => $capture->Advertencias === null ? [] : json_decode($capture->Advertencias, true),
                'sesion_cerrada' => $capture->SesionCerrada === null ? null : (bool) $capture->SesionCerrada,
                'hash' => $capture->HashContenido, 'integridad_verificada' => $this->repository->verify($id),
                'seleccionada' => $state === 'validado',
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
