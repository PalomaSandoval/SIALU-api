<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    App\Services\Ingestion\IngestionDatabase::verifyConfiguredConnection();
    $db=Illuminate\Support\Facades\DB::connection('sqlsrv');
    $source=App\Services\Ingestion\SourceIdentity::id('paloalto');
    config(['sialu.paloalto.timezone'=>$db->table('dbo.catcFuentes')->where('IdFuente',$source)->value('ZonaHoraria')]);
    $apply=in_array('--apply',$argv,true); $processed=0; $missing=0;
    $db->table('dbo.movdEventos as e')->leftJoin('dbo.movdstgEventos as s','s.IdStgEvento','=','e.IdStgEvento')
        ->leftJoin('dbo.movdEventoPaloAlto as p',fn($q)=>$q->on('p.IdEvento','=','e.IdEvento')->on('p.FechaEvento','=','e.FechaEvento'))
        ->where('e.IdFuente',$source)->whereNull('p.IdEvento')->select('e.IdEvento','e.FechaEvento','s.Payload')->orderBy('e.IdEvento')
        ->chunkById(200,function($rows)use($db,$apply,&$processed,&$missing){
            $db->transaction(function()use($rows,$db,$apply,&$processed,&$missing){
                foreach($rows as $row){
                    if($row->Payload===null){$missing++;continue;}
                    $context=(new App\Integrations\PaloAlto\PaloAltoContext)->parse(json_decode($row->Payload,true,512,JSON_THROW_ON_ERROR));
                    if($apply)$db->table('dbo.movdEventoPaloAlto')->insert(['FechaEvento'=>$row->FechaEvento,'IdEvento'=>$row->IdEvento]+$context);
                    $processed++;
                }
            });
        },'e.IdEvento','IdEvento');
    echo json_encode(['mode'=>$apply?'aplicado':'simulacion','contextos'=>$processed,'sin_payload'=>$missing],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
