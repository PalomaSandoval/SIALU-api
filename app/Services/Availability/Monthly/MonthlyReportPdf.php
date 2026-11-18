<?php

namespace App\Services\Availability\Monthly;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF of a stored monthly report (MonthlyReportService::report()). It only formats stored values: no percentage,
 * subtotal or count is computed here. Letter landscape; the table header repeats on every page (dompdf repeats
 * <thead>); a PARCIAL capture carries a visible mark on every page.
 */
class MonthlyReportPdf
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const STATES = [
        'oficial' => 'Oficial', 'vigente' => 'Vigente', 'provisional' => 'Provisional', 'parcial' => 'Parcial',
        'no_validada' => 'No validada', 'no_seleccionada' => 'Captura no seleccionada', 'no_disponible' => 'No disponible',
    ];

    public static function filename(array $report): string
    {
        $suffix = $report['captura'] === null ? 'no-disponible' : 'captura-'.$report['captura']['id'];

        return 'disponibilidad-orion-'.$report['mes'].'-'.$suffix.'.pdf';
    }

    public static function render(array $report): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(base_path('vendor/dompdf/dompdf'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(self::html($report), 'UTF-8');
        $pdf->setPaper('letter', 'landscape');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $footer = 'Disponibilidad mensual de Orion · '.self::monthName($report['mes'])
            .($report['captura'] ? ' · Captura '.$report['captura']['id'].' · SHA-256 '.$report['captura']['hash'] : '');
        $canvas->page_text(28, $canvas->get_height() - 20, $footer, $font, 6, [0.35, 0.35, 0.35]);
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 20, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 6, [0.35, 0.35, 0.35]);
        if ($report['marca_parcial']) {
            $canvas->page_text($canvas->get_width() - 175, 14, 'PARCIAL — NO OFICIAL', $font, 11, [0.75, 0.1, 0.1]);
        }

        return $pdf->output();
    }

    public static function html(array $report): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $days = $report['dias_mes'];
        $coverage = array_column($report['dias'], null, 'dia');
        $state = self::STATES[$report['estado_mes']] ?? $report['estado_mes'];
        $c = $report['captura'];

        $title = 'Disponibilidad mensual de Orion — '.self::monthName($report['mes']);
        $meta = [];
        if ($c !== null) {
            $meta[] = 'Estado: <b>'.$e($state).'</b>'.($report['marca_parcial'] ? ' <span class="parcial">PARCIAL</span>' : '');
            $meta[] = 'Fuente del porcentaje: Orion «Availability Statistics», periodo «'.$e($c['periodo_orion']).'»';
            $meta[] = 'Captura '.$c['id'].' ('.$e(strtolower($c['origen'])).') del '.$e(self::local($c['inicio'])).' (hora de Orion, UTC-6)';
            $meta[] = 'Nodos: '.$c['conteos']['total'].' · incluidos '.$c['conteos']['ok'].' · sin datos '.$c['conteos']['sin_datos']
                .' · con error '.$c['conteos']['error'].' · no consultados '.$c['conteos']['no_consultados'];
            if (! $c['integridad_verificada']) {
                $meta[] = '<span class="parcial">ADVERTENCIA: el contenido no coincide con su hash; no usar.</span>';
            }
        } else {
            $meta[] = 'Estado: <b>No disponible</b>. No existe una captura de este mes.';
        }
        $general = $report['resumen'][0] ?? null;
        if ($general !== null) {
            $meta[] = 'Disponibilidad general: <b>'.($general['disponibilidad'] === null ? 'Sin datos' : $e($general['disponibilidad']).' %').'</b>';
        }

        $head = '<tr><th class="area">Área</th><th class="grupo">Grupo</th><th class="nodo">Nodo</th>';
        for ($d = 1; $d <= $days; $d++) {
            $head .= '<th class="d">'.$d.'</th>';
        }
        $head .= '<th class="disp">Disponibilidad mensual</th></tr>';

        $cell = function (?int $value, int $day) use ($coverage): string {
            $cov = $coverage[$day]['cobertura'] ?? 'SD';
            if ($cov === 'FUTURO') {
                return '<td class="d fut">–</td>';
            }
            if ($value === null) {
                return '<td class="d sd">s/d</td>';
            }

            return $value === 0 ? '<td class="d zero">0</td>' : '<td class="d hit">'.$value.'</td>';
        };
        $percent = fn (?string $p) => $p === null ? '<span class="nd">Sin datos</span>' : $p.' %';
        $counts = fn (array $s) => $s['incluidos'].' de '.$s['total_nodos'].' nodos incluidos'
            .($s['sin_datos'] ? ' · '.$s['sin_datos'].' sin datos' : '').($s['con_error'] ? ' · '.$s['con_error'].' con error' : '')
            .($s['no_consultados'] ? ' · '.$s['no_consultados'].' no consultados' : '');

        $nodesByGroup = [];
        foreach ($report['nodos'] as $node) {
            $nodesByGroup[($node['area'] ?? "\0")."\x1F".($node['grupo'] ?? "\0")][] = $node;
        }
        $rows = '';
        foreach ($report['resumen'] as $s) {
            if ($s['nivel'] === 'GENERAL') {
                continue;
            }
            $area = $s['area'] ?? 'Sin área';
            if ($s['nivel'] === 'AREA') {
                $rows .= '<tr class="sum area-row"><td colspan="2">'.$e($area).'</td><td>Resumen del área · caídas iniciadas<br><small>'.$counts($s).'</small></td>';
            } else {
                $rows .= '<tr class="sum group-row"><td>'.$e($area).'</td><td>'.$e($s['grupo'] ?? 'Sin grupo').'</td><td>Resumen del grupo · caídas iniciadas<br><small>'.$counts($s).'</small></td>';
            }
            for ($d = 1; $d <= $days; $d++) {
                $rows .= $cell($s['caidas_por_dia'][$d] ?? null, $d);
            }
            $rows .= '<td class="disp">'.$percent($s['disponibilidad']).'</td></tr>';
            if ($s['nivel'] === 'GRUPO') {
                foreach ($nodesByGroup[($s['area'] ?? "\0")."\x1F".($s['grupo'] ?? "\0")] ?? [] as $node) {
                    $rows .= '<tr><td>'.$e($area).'</td><td>'.$e($s['grupo'] ?? 'Sin grupo').'</td><td>'.$e($node['nombre'] ?? 'Nodo sin nombre').' <small>#'.$node['node_id'].'</small></td>';
                    for ($d = 1; $d <= $days; $d++) {
                        $rows .= $cell($node['dias'][$d] ?? null, $d);
                    }
                    $value = match ($node['estado']) {
                        'ok' => $e($node['disponibilidad']).' %',
                        'sin_datos' => '<span class="nd">Sin datos</span>',
                        'error' => '<span class="err">Error</span>',
                        default => '<span class="err">No consultado</span>',
                    };
                    $rows .= '<td class="disp">'.$value.'</td></tr>';
                }
            }
        }

        $sources = [];
        foreach ($report['dias'] as $d) {
            if ($d['cobertura'] === 'COMPLETA') {
                $sources[$d['fuente'].($d['captura_origen'] ? ' (captura '.$d['captura_origen'].')' : '')][] = $d['dia'];
            }
        }
        $sourceText = $sources === [] ? 'ningún día' : implode('; ', array_map(fn ($k, $v) => $e($k).': '.self::ranges($v), array_keys($sources), $sources));

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 26px 24px 30px 24px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 6.4pt; color: #111; }
h1 { font-size: 11pt; margin: 0 0 3px; }
.meta { margin: 0 0 4px; line-height: 1.45; }
.legend { margin: 0 0 5px; color: #333; }
.parcial { color: #b01818; font-weight: bold; }
table { width: 100%; border-collapse: collapse; table-layout: auto; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
th, td { border: 0.4pt solid #9aa0a6; padding: 1.3px 1.5px; vertical-align: middle; overflow: hidden; }
th { background: #e6e9ee; font-weight: bold; text-align: center; }
th.area { width: 54px; } th.grupo { width: 50px; } th.nodo { width: 118px; } th.disp { width: 50px; }
td.d { text-align: center; } td.disp { text-align: right; white-space: nowrap; }
td.sd { color: #6b6b6b; font-style: italic; font-size: 5.4pt; } td.fut { color: #9a9a9a; }
td.zero { color: #9a9a9a; } td.hit { font-weight: bold; }
tr.sum td { font-weight: bold; } tr.area-row td { background: #d9e2ef; } tr.group-row td { background: #eef2f7; }
small { font-weight: normal; color: #444; font-size: 5.6pt; }
.nd { color: #555; font-style: italic; } .err { color: #b01818; }
</style></head><body>
<h1>'.$e($title).'</h1>
<div class="meta">'.implode('<br>', $meta).'</div>
<div class="legend">Columnas 1–'.$days.': caídas iniciadas ese día (hora local de Orion). «0»: sin caídas, con cobertura de eventos demostrada. «s/d»: no se pudo demostrar la cobertura de eventos. «–»: el día aún no ocurría. Filas de resumen: suma de caídas iniciadas de sus nodos y promedio de sus nodos incluidos. Los días no modifican el porcentaje. Cobertura: '.$sourceText.'.</div>
<table><thead>'.$head.'</thead><tbody>'.$rows.'</tbody></table>
</body></html>';
    }

    private static function monthName(string $month): string
    {
        [$y, $m] = explode('-', $month);

        return self::MONTHS[(int) $m - 1].' de '.$y;
    }

    private static function local(?string $iso): string
    {
        return $iso === null ? '—' : CarbonImmutable::parse($iso)->utc()->addHours(DowntimeDays::OFFSET_HOURS)->format('d/m/Y H:i');
    }

    /** [20,21,22,25] → "20–22, 25" */
    private static function ranges(array $days): string
    {
        sort($days);
        $out = [];
        $start = $prev = null;
        foreach ([...$days, null] as $d) {
            if ($d !== null && $prev !== null && $d === $prev + 1) {
                $prev = $d;

                continue;
            }
            if ($start !== null) {
                $out[] = $start === $prev ? (string) $start : $start.'–'.$prev;
            }
            $start = $prev = $d;
        }

        return implode(', ', $out);
    }
}
