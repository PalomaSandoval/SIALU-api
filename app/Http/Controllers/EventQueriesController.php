<?php
namespace App\Http\Controllers;

use App\Services\Catalogos\NetworkZoneDiscovery;
use App\Services\ThreatSeverity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventQueriesController extends Controller
{
    public function index(Request $request, NetworkZoneDiscovery $discovery)
    {
        $data = $request->validate([
            'fuente_id' => ['sometimes', 'required', 'integer', 'min:1'], 'serial' => ['sometimes', 'required', 'string', 'max:100'],
            'vsys' => ['sometimes','required','string','max:100'],
            'zona' => ['sometimes', 'required', 'string', 'max:100'], 'tipo' => ['sometimes', 'required', 'in:THREAT,TRAFFIC'],
            'severidad' => ['sometimes', 'required', 'in:'.implode(',', ThreatSeverity::keys())], 'page' => ['sometimes', 'integer', 'min:1'],
            'subtipo' => ['sometimes', 'nullable', 'string', 'max:200'], 'amenaza' => ['sometimes', 'required', 'string', 'max:310'],
            'ip' => ['nullable', 'ip'], 'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('desde') ? ['after_or_equal:desde'] : []),
        ]);
        $q = DB::connection('sqlsrv')->query()->fromSub($discovery->events(), 'v')
            ->leftJoin('dbo.catcAcciones as a', 'a.IdAccion', '=', 'v.IdAccion');
        foreach (['fuente_id' => 'v.IdFuente', 'serial' => 'v.Serial', 'vsys'=>'v.Vsys'] as $key => $column) {
            if (isset($data[$key])) $q->where($column, $data[$key]);
        }
        if (isset($data['tipo'])) $q->where('v.TipoEventoOrigen', $data['tipo']);
        if (isset($data['severidad'])) ThreatSeverity::filter($q, $data['severidad']);
        // An empty subtipo (stored as null) selects events without a subtype, as the threat view lists them.
        if (array_key_exists('subtipo', $data)) ThreatSeverity::filterSubtype($q, $data['subtipo']);
        if (isset($data['amenaza']) && !ThreatSeverity::filterThreat(ThreatSeverity::joinThreat($q), $data['amenaza'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amenaza' => 'El identificador de amenaza no es válido.']);
        }
        if (isset($data['zona'])) $q->where(fn ($query) => $query->where('v.ZonaOrigen', $data['zona'])->orWhere('v.ZonaDestino', $data['zona']));
        if (!empty($data['ip'])) $q->where(fn ($query) => $query->where('v.IPOrigen', $data['ip'])->orWhere('v.IPDestino', $data['ip']));
        if (!empty($data['desde'])) $q->where('v.FechaEvento', '>=', $data['desde'].' 00:00:00');
        if (!empty($data['hasta'])) $q->where('v.FechaEvento', '<', (new \DateTimeImmutable($data['hasta']))->modify('+1 day')->format('Y-m-d'));
        $rows = $q->orderByDesc('v.FechaEvento')->orderByDesc('v.IdEvento')->paginate(50, ['v.*', 'a.Nombre as Accion'], 'page', $data['page'] ?? 1);
        return response()->json(['data' => $rows->items(), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]);
    }
}
