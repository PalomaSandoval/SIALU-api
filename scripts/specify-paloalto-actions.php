<?php

// Run without --persist to preview. Changes only catalog names/mappings, never event rows.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$source = App\Services\Ingestion\SourceIdentity::id('paloalto');
$db = Illuminate\Support\Facades\DB::connection('sqlsrv');
$definitions = [
    'deny' => ['Tráfico denegado', 'El firewall deniega el tráfico por una política de seguridad.'],
    'reset-both' => ['Conexión interrumpida en ambos extremos', 'El firewall interrumpe la conexión TCP enviando un restablecimiento al cliente y al servidor.'],
    'reset-server' => ['Conexión interrumpida hacia el servidor', 'El firewall interrumpe la conexión TCP enviando un restablecimiento únicamente al servidor.'],
];
$persist = in_array('--persist', $argv, true);
$db->beginTransaction();
try {
    $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=10000; SELECT @r AS result", ['SIALU:ingestion:source:'.$source]);
    if ((int)$lock->result < 0) throw new RuntimeException('No se obtuvo el bloqueo de la fuente.');
    foreach ($definitions as $raw => [$name, $description]) {
        $mappings = $db->table('dbo.catdMapeoAccion')->where('IdFuente', $source)->where('ValorOrigen', $raw)->lockForUpdate()->get();
        if ($mappings->count() !== 1 || !$mappings[0]->Vigente) throw new RuntimeException('Falta una equivalencia única vigente para '.$raw);
        $current = $db->table('dbo.catcAcciones')->where('IdAccion', $mappings[0]->IdAccion)->first();
        if (!$current || $current->Resultado !== 'BLOQUEADO') throw new RuntimeException('Clasificación inesperada para '.$raw);
        $target = $db->table('dbo.catcAcciones')->where('Nombre', $name)->first();
        if ($target && (!$target->Vigente || $target->Resultado !== 'BLOQUEADO')) throw new RuntimeException('Nombre existente incompatible: '.$name);
        if ($persist) {
            $id = $target?->IdAccion;
            if ($id === null) {
                $saved = $app->make(App\Http\Controllers\Catalogos\ReferenceCatalogController::class)->store(
                    Illuminate\Http\Request::create('/', 'POST', ['nombre' => $name, 'descripcion' => $description, 'resultado' => 'BLOQUEADO']), 'acciones'
                )->getData(true)['data'];
                $id = $saved['id'];
            }
            $db->table('dbo.catdMapeoAccion')->where('IdMapeoAccion', $mappings[0]->IdMapeoAccion)->update(['IdAccion' => $id]);
        }
        echo $raw.' → '.$name.PHP_EOL;
    }
    if ($persist) $db->commit(); else $db->rollBack();
    echo $persist ? "Guardado. Eventos históricos sin cambios.\n" : "Vista previa; sin cambios. Usa --persist para guardar.\n";
} catch (Throwable $e) { $db->rollBack(); throw $e; }
