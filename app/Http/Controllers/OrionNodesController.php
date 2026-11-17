<?php

namespace App\Http\Controllers;

use App\Integrations\Orion\OrionClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Read only: NodeID and Caption of every node in Orion.Nodes, queried live through SWIS, to pick one node. */
class OrionNodesController extends Controller
{
    public function index()
    {
        try {
            $rows = $this->client()->query('SELECT n.NodeID, n.Caption FROM Orion.Nodes n ORDER BY n.Caption');
        } catch (Throwable $e) {
            Log::warning('No fue posible listar los nodos de Orion.', ['type' => class_basename($e)]);

            return response()->json(['message' => 'No fue posible obtener la lista de nodos de Orion.'], 503);
        }

        $nodes = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? filter_var($row['NodeID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                continue;
            }
            $caption = is_string($row['Caption'] ?? null) ? trim($row['Caption']) : '';
            $nodes[] = ['node_id' => $id, 'nombre' => $caption];
        }

        return response()->json(['data' => $nodes]);
    }

    /** Separate so tests can replace the SWIS client. */
    protected function client(): OrionClient
    {
        return new OrionClient;
    }
}
