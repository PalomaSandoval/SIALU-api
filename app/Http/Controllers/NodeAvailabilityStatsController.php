<?php

namespace App\Http\Controllers;

use App\Integrations\Orion\OrionClient;
use App\Integrations\Orion\OrionWebClient;
use App\Integrations\Orion\OrionWebException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Availability Statistics» of one Orion node exactly as the Orion web console shows it (seven periods, Orion's own
 * calculation and local time). Read only; queried live through a web session that is closed after each request.
 *
 * Orion answers an empty table both for a node without data and for a NodeID that does not exist, so the NodeID is
 * first confirmed in Orion.Nodes (SWIS): unknown → 404; known and empty → `estado: sin_datos`; a failure → 503,
 * never «sin datos».
 */
class NodeAvailabilityStatsController extends Controller
{
    public function show(string $nodeId)
    {
        if (! preg_match('/^[1-9]\d{0,9}$/', $nodeId) || (int) $nodeId > OrionWebClient::MAX_NODE_ID) {
            return response()->json([
                'message' => 'El NodeID debe ser un entero positivo.',
                'errors' => ['node_id' => ['El NodeID debe ser un entero positivo.']],
            ], 422);
        }
        $id = (int) $nodeId;

        try {
            if (! $this->nodeExists($id)) {
                return response()->json(['message' => 'El nodo '.$id.' no existe en Orion.'], 404);
            }
            $stats = $this->client()->availabilityStats($id);
            // Still there after an empty answer: only then is it a node without data.
            if ($stats['vacia'] && ! $this->nodeExists($id)) {
                return response()->json(['message' => 'El nodo '.$id.' no existe en Orion.'], 404);
            }
        } catch (OrionWebException $e) {
            // These messages are written by SIALU and never carry credentials, cookies or Orion's response body.
            Log::warning('No fue posible consultar Availability Statistics en Orion.', ['node_id' => $id, 'motivo' => $e->getMessage()]);

            return response()->json(['message' => 'No fue posible consultar Orion: '.$e->getMessage()], 503);
        } catch (Throwable $e) {
            Log::warning('No fue posible consultar Availability Statistics en Orion.', ['node_id' => $id, 'type' => class_basename($e)]);

            return response()->json(['message' => 'No fue posible consultar Orion.'], 503);
        }

        return response()->json(['data' => [
            'node_id' => $id,
            'estado' => $stats['vacia'] ? 'sin_datos' : 'con_datos',
            'consultado_en' => CarbonImmutable::now('UTC')->toIso8601ZuluString('millisecond'),
            'periodos' => $stats['periodos'],
            'sesion_cerrada' => $stats['sesion_cerrada'],
        ]]);
    }

    /** Separate so the isolated tests can replace the web session. */
    protected function client(): OrionWebClient
    {
        return new OrionWebClient;
    }

    /** Orion.Nodes through SWIS; separate so the isolated tests can replace it. */
    protected function nodeExists(int $id): bool
    {
        return app(OrionClient::class)->query('SELECT n.NodeID FROM Orion.Nodes n WHERE n.NodeID = '.$id) !== [];
    }
}
