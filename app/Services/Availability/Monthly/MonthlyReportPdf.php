<?php

namespace App\Services\Availability\Monthly;

use Carbon\CarbonImmutable;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Fills the editable Word template with values from a stored monthly report. */
class MonthlyReportPdf
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    private const TEMPLATE = 'reports/Plantilla Reporte Mensual.docx';

    public static function filename(array $report): string
    {
        return 'disponibilidad-orion-'.$report['mes'].'.pdf';
    }

    public static function render(array $report): string
    {
        $templatePath = resource_path(self::TEMPLATE);
        if (! is_file($templatePath)) {
            throw new RuntimeException('No se encontró la plantilla de Word del reporte mensual.');
        }
        $directory = storage_path('app').DIRECTORY_SEPARATOR.'sialu-report-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('No se pudo crear el directorio temporal del reporte.');
        }
        try {
            $template = new TemplateProcessor($templatePath);
            $template->setValues(self::fields($report));
            $template->setComplexBlock('TABLA_REPORTE', self::table($report));
            $docxPath = $directory.DIRECTORY_SEPARATOR.'reporte.docx';
            $template->saveAs($docxPath);
            unset($template);
            clearstatcache(true, $docxPath);
            $docxBytes = is_file($docxPath) ? filesize($docxPath) : false;
            $archive = new \ZipArchive;
            $zipStatus = $docxBytes ? $archive->open($docxPath) : false;
            if ($zipStatus === true) {
                $archive->close();
            } else {
                throw new RuntimeException('La plantilla rellenada no es un DOCX válido (bytes: '.var_export($docxBytes, true).', zip: '.var_export($zipStatus, true).').');
            }
            $binary = self::libreOfficeBinary();
            $profile = str_replace('\\', '/', $directory).'/profile';
            // The PHP web server adds HTTP_* and other request variables to child processes.
            // LibreOffice cannot open DOCX files with that inherited environment on Windows.
            $cleanEnv = array_fill_keys(array_keys(array_merge($_ENV, getenv())), false);
            foreach (['SystemRoot', 'SystemDrive', 'WINDIR', 'PATH', 'PATHEXT', 'COMSPEC', 'TEMP', 'TMP',
                'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'HOMEDRIVE', 'HOMEPATH'] as $key) {
                $cleanEnv[$key] = getenv($key);
            }
            $process = new Process([
                $binary, '-env:UserInstallation=file:///'.ltrim($profile, '/'), '--headless',
                '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $directory, $docxPath,
            ], $directory, $cleanEnv);
            $process->setTimeout(120);
            $process->run();
            $pdfPath = $directory.DIRECTORY_SEPARATOR.'reporte.pdf';
            if (! $process->isSuccessful() || ! is_file($pdfPath)) {
                throw new RuntimeException('LibreOffice no pudo convertir el reporte: '.$process->getErrorOutput());
            }
            $pdf = file_get_contents($pdfPath);
            if ($pdf === false || ! str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException('El PDF generado no es válido.');
            }
            return $pdf;
        } finally {
            self::removeTemporaryDirectory($directory);
        }
    }

    /** All values come from the stored report; this class does not recalculate availability. */
    public static function fields(array $report): array
    {
        [$year, $month] = explode('-', $report['mes']);
        $capture = $report['captura'];
        $generated = CarbonImmutable::now('UTC')->addHours(DowntimeDays::OFFSET_HOURS);
        $general = $report['resumen'][0] ?? null;
        $count = fn (string $key) => $capture === null ? '—' : (string) $capture['conteos'][$key];
        return [
            'mes' => ucfirst(self::MONTHS[(int) $month - 1]), 'anio' => $year,
            'fecha' => $generated->format('d/m/Y'), 'hora' => $generated->format('H:i'),
            'NumeroNodos' => $count('total'), 'NodosDisponibles' => $count('ok'),
            'NodosSinDatos' => $count('sin_datos'), 'NodosConError' => $count('error'),
            'NodosNoConsultados' => $count('no_consultados'),
            'disponibilidadGeneral' => $general === null || $general['disponibilidad'] === null
                ? 'Sin datos' : $general['disponibilidad'].' %',
            'marcaParcial' => ! empty($report['marca_parcial']) ? 'PARCIAL — NO OFICIAL' : '',
        ];
    }

    private static function table(array $report): Table
    {
        $days = (int) $report['dias_mes'];
        $dayWidth = (int) floor((14976 - 950 - 950 - 2200 - 1000) / $days);
        $widths = array_merge([950, 950, 2200], array_fill(0, $days, $dayWidth), [1000]);
        $table = new Table(['borderSize' => 3, 'borderColor' => 'A6ADB5', 'cellMargin' => 18, 'layout' => 'fixed']);
        self::addRow($table, $widths, array_merge(['Área', 'Grupo', 'Nodo'], range(1, $days), ['Disponibilidad mensual']), 'E6E9EE', true);
        $nodesByGroup = [];
        foreach ($report['nodos'] as $node) {
            $nodesByGroup[($node['area'] ?? "\0")."\x1F".($node['grupo'] ?? "\0")][] = $node;
        }
        foreach ($report['resumen'] as $summary) {
            if ($summary['nivel'] === 'GENERAL') {
                continue;
            }
            $area = $summary['area'] ?? 'Sin área';
            $isArea = $summary['nivel'] === 'AREA';
            $values = [$area, $isArea ? '' : ($summary['grupo'] ?? 'Sin grupo'), $isArea ? 'Resumen del área' : 'Resumen del grupo'];
            for ($day = 1; $day <= $days; $day++) {
                $values[] = self::dayValue($summary['caidas_por_dia'][$day] ?? null);
            }
            $values[] = self::percent($summary['disponibilidad']);
            self::addRow($table, $widths, $values, $isArea ? 'D9E2EF' : 'EEF2F7', true);
            if ($isArea) {
                continue;
            }
            foreach ($nodesByGroup[($summary['area'] ?? "\0")."\x1F".($summary['grupo'] ?? "\0")] ?? [] as $node) {
                // Área y grupo se leen en la fila de resumen inmediatamente anterior.
                $values = ['', '', ($node['nombre'] ?? 'Nodo sin nombre').' #'.$node['node_id']];
                for ($day = 1; $day <= $days; $day++) {
                    $values[] = self::dayValue($node['dias'][$day] ?? null);
                }
                $values[] = match ($node['estado']) {
                    'ok' => self::percent($node['disponibilidad']),
                    'sin_datos' => 'Sin datos', 'error' => 'Error', default => 'No consultado',
                };
                self::addRow($table, $widths, $values);
            }
        }
        return $table;
    }

    private static function addRow(Table $table, array $widths, array $values, ?string $fill = null, bool $bold = false): void
    {
        $table->addRow(null, ['cantSplit' => true, 'tblHeader' => $values[0] === 'Área']);
        foreach ($values as $index => $value) {
            $cell = $table->addCell($widths[$index], $fill === null ? [] : ['bgColor' => $fill]);
            $cell->addText((string) $value, ['name' => 'Arial', 'size' => $index < 3 ? 5.5 : 5, 'bold' => $bold],
                ['spaceAfter' => 0, 'alignment' => $index >= 3 ? 'center' : 'left']);
        }
    }

    private static function dayValue(?int $value): string
    {
        return $value === null ? 's/d' : (string) $value;
    }

    private static function percent(?string $value): string
    {
        return $value === null ? 'Sin datos' : $value.' %';
    }

    private static function libreOfficeBinary(): string
    {
        $configured = config('sialu.libreoffice_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $local = getenv('LOCALAPPDATA');
        foreach (array_filter([
            $local ? $local.'\\Programs\\LibreOfficePortable\\App\\libreoffice\\program\\soffice.com' : null,
            'C:\\Program Files\\LibreOffice\\program\\soffice.com',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        ]) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return 'libreoffice';
    }

    private static function removeTemporaryDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

}
