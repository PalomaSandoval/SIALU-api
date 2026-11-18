<?php

namespace App\Http\Controllers;

use App\Services\Availability\Monthly\MonthlyCapturePlanner;
use App\Services\Availability\Monthly\MonthlyCaptureRepository;
use App\Services\Availability\Monthly\MonthlyReportPdf;
use App\Services\Availability\Monthly\MonthlyReportService;
use App\Services\SessionManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Monthly availability report of Orion (docs/disponibilidad-mensual.md). The API never queries Orion: requesting a
 * capture only registers it (the scheduler runs it) and every report or PDF is rebuilt from stored captures.
 * Users see one report per month (GET /meses/{mes}); captures, their history and requests are administrative
 * diagnosis only.
 */
class MonthlyAvailabilityController extends Controller
{
    /** Minimum minutes between manual capture requests. */
    public const REQUEST_INTERVAL_MINUTES = 15;

    public function __construct(private ?MonthlyCaptureRepository $repository = null)
    {
        $this->repository ??= new MonthlyCaptureRepository;
    }

    /** POST /capturas (administrators): explicit request to query Orion again, even for a month already validated. */
    public function request(SessionManager $session)
    {
        $last = $this->repository->lastManualRequest();
        if ($last !== null && $last->gt(CarbonImmutable::now('UTC')->subMinutes(self::REQUEST_INTERVAL_MINUTES))) {
            return response()->json(['message' => 'Ya se solicitó una captura hace menos de '.self::REQUEST_INTERVAL_MINUTES.' minutos.'], 429);
        }
        $userId = $session->userValue('IdUsuario');
        $request = $this->repository->request('MANUAL', is_numeric($userId) ? (int) $userId : null);
        if (! $request['creada']) {
            return response()->json(['message' => 'Ya hay una captura pendiente o en proceso.', 'data' => $this->status($request['id'])], 409);
        }

        return response()->json(['data' => $this->status($request['id'])], 202);
    }

    /** GET /capturas/{id} */
    public function show(string $id)
    {
        $capture = ctype_digit($id) ? $this->status((int) $id) : null;

        return $capture === null ? response()->json(['message' => 'La captura no existe.'], 404) : response()->json(['data' => $capture]);
    }

    /** GET /capturas?mes=YYYY-MM (administrators): stored captures, selection history and automatic state. */
    public function index(Request $request)
    {
        try {
            $month = $this->validMonth($request->query('mes'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['mes' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => [
            'mes' => $month,
            'capturas' => (new MonthlyReportService($this->repository))->captures($month),
            'selecciones' => $this->repository->selections($month),
            'en_curso' => $this->repository->openRequest(),
            'automatico' => (new MonthlyCapturePlanner($this->repository, MonthlyReportService::startAt()))->status($month),
        ]]);
    }

    /** GET /meses/{mes}: the single report of the month. */
    public function month(Request $request, string $month)
    {
        try {
            return response()->json(['data' => $this->report($request, $month)]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['mes' => [$e->getMessage()]]], 422);
        }
    }

    /**
     * GET /meses/{mes}/pdf[?captura=id]: rendered only here, when the user presses «Exportar PDF». `captura` is the
     * capture behind the report on screen; if the month's report changed meanwhile the export is refused (409) so the
     * PDF never differs from what the user saw.
     */
    public function pdf(Request $request, string $month)
    {
        try {
            $report = $this->report($request, $month);
            $expected = $request->query('captura');
            if ($expected !== null && ! ctype_digit((string) $expected)) {
                throw new InvalidArgumentException('La captura debe ser un número.');
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['mes' => [$e->getMessage()]]], 422);
        }
        if ($report['captura'] === null) {
            return response()->json(['message' => 'No disponible: todavía no hay un reporte de '.$report['mes'].'.'], 404);
        }
        if ($expected !== null && (int) $expected !== $report['captura']['id']) {
            return response()->json(['message' => 'El reporte del mes se actualizó. Recarga la página y vuelve a exportar.'], 409);
        }
        if (! $report['captura']['integridad_verificada']) {
            Log::error('La captura de disponibilidad mensual no coincide con su hash.', ['captura' => $report['captura']['id']]);

            return response()->json(['message' => 'La captura no coincide con su hash; no se genera el PDF.'], 409);
        }
        try {
            $pdf = MonthlyReportPdf::render($report);
        } catch (Throwable $e) {
            Log::warning('No fue posible generar el PDF de disponibilidad mensual.', ['type' => class_basename($e)]);

            return response()->json(['message' => 'No fue posible generar el PDF.'], 500);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.MonthlyReportPdf::filename($report).'"',
            'Cache-Control' => 'no-store',
            'X-Captura' => (string) $report['captura']['id'],
            'X-Hash-Contenido' => (string) $report['captura']['hash'],
        ]);
    }

    private function report(Request $request, string $month): array
    {
        return (new MonthlyReportService($this->repository))->report($this->validMonth($month));
    }

    private function validMonth(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException('El mes debe tener el formato YYYY-MM.');
        }

        return $value;
    }

    private function status(int $id): ?array
    {
        $c = $this->repository->find($id);
        if ($c === null) {
            return null;
        }

        return [
            'id' => $id, 'origen' => $c->Origen, 'estado' => strtolower($c->Estado), 'valida' => (bool) $c->Valida,
            'solicitada' => MonthlyCaptureRepository::iso($c->FechaSolicitud), 'inicio' => MonthlyCaptureRepository::iso($c->FechaInicio),
            'fin' => MonthlyCaptureRepository::iso($c->FechaFin), 'latido' => MonthlyCaptureRepository::iso($c->Latido),
            'avance' => ['consultados' => (int) $c->NodosConsultados, 'total' => $c->TotalNodos === null ? null : (int) $c->TotalNodos],
            'mes_anterior' => $c->MesAnterior, 'mes_actual' => $c->MesActual,
            'conteos' => $c->NodosOk === null ? null : ['ok' => (int) $c->NodosOk, 'sin_datos' => (int) $c->NodosSinDatos,
                'error' => (int) $c->NodosError, 'no_consultados' => (int) $c->NodosNoConsultados],
            'motivos_invalidez' => $c->MotivosInvalidez === null ? [] : json_decode($c->MotivosInvalidez, true),
            'advertencias' => $c->Advertencias === null ? [] : json_decode($c->Advertencias, true),
            'sesion_cerrada' => $c->SesionCerrada === null ? null : (bool) $c->SesionCerrada,
            'mensaje_error' => $c->MensajeError, 'hash' => $c->HashContenido,
        ];
    }
}
