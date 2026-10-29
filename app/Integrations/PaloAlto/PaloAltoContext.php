<?php
namespace App\Integrations\PaloAlto;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Versioned enrichment, deliberately excluded from the existing v1 event fingerprint. */
final class PaloAltoContext
{
    public function parse(array $raw): array
    {
        $text = static function (string $key, int $max) use ($raw): ?string {
            $value = $raw[$key] ?? null;
            if ($value === null || $value === '') return null;
            if (!is_scalar($value)) throw new InvalidArgumentException('Campo no escalar: '.$key);
            $value = trim((string)$value);
            if (strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2 > $max) throw new InvalidArgumentException('Campo excede capacidad SQL: '.$key);
            return $value === '' ? null : $value;
        };
        $result = [];
        foreach (['Serial'=>['serial',100], 'Vsys'=>['vsys',100], 'Dispositivo'=>['device_name',200],
            'ReglaUUID'=>['rule_uuid',36], 'VersionConfiguracion'=>['config_ver',100], 'IdSesion'=>['sessionid',30],
            'IdSesionPadre'=>['parent_session_id',30], 'InterfazEntrada'=>['inbound_if',100], 'InterfazSalida'=>['outbound_if',100],
            'MotivoFin'=>['session_end_reason',100], 'DireccionAmenaza'=>['direction',100], 'VersionContenido'=>['contentver',100], 'IdCaptura'=>['pcap_id',100]] as $column=>[$key,$max]) $result[$column]=$text($key,$max);
        $result['Vsys'] ??= $text('vsys_id',100);
        if ($result['ReglaUUID'] !== null && !preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D',$result['ReglaUUID'])) throw new InvalidArgumentException('UUID de regla inválido.');
        foreach (['IPNATOrigen'=>'natsrc','IPNATDestino'=>'natdst'] as $column=>$key) {
            $value=$text($key,45);
            if ($value !== null && filter_var($value,FILTER_VALIDATE_IP) === false) throw new InvalidArgumentException('IP NAT inválida.');
            $result[$column]=$value === null ? null : inet_ntop(inet_pton($value));
        }
        foreach (['PuertoNATOrigen'=>'natsport','PuertoNATDestino'=>'natdport','DuracionSegundos'=>'elapsed','Paquetes'=>'packets','PaquetesEnviados'=>'pkts_sent','PaquetesRecibidos'=>'pkts_received','Repeticiones'=>'repeatcnt'] as $column=>$key) {
            $value=$text($key,30); $number=$value === null ? null : (ltrim($value,'0') ?: '0');
            if ($number !== null && (!ctype_digit($value) || strlen($number)>19 || (strlen($number)===19 && strcmp($number,'9223372036854775807')>0))) throw new InvalidArgumentException('Contador inválido: '.$key);
            if (str_starts_with($column,'Puerto') && $number !== null && (int)$number>65535) throw new InvalidArgumentException('Puerto NAT inválido.');
            $result[$column]=$number;
        }
        foreach (['InicioSesion'=>'start','FechaRecepcionOrigen'=>'receive_time','FechaAltaResolucion'=>'high_res_timestamp'] as $column=>$key) {
            $value=$text($key,50);
            $result[$column]=$value === null ? null : $this->date($value);
        }
        $application=[];
        foreach (['category_of_app','subcategory_of_app','technology_of_app','characteristic_of_app','risk_of_app','is_saas_of_app','sanctioned_state_of_app','tunneled_app'] as $key) $application[$key]=$text($key,2000);
        $result['ContextoAplicacion']=json_encode($application,JSON_THROW_ON_ERROR);
        $result['HuellaContexto']=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR));
        return $result;
    }

    private function date(string $value): string
    {
        if (!preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,7}))?(Z|[+-]\d{2}:\d{2})?$/D',$value,$m)
            || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]) || (int)$m[4]>23 || (int)$m[5]>59 || (int)$m[6]>59) throw new InvalidArgumentException('Fecha de contexto inválida.');
        $zone=config('sialu.paloalto.timezone');
        if (empty($m[8]) && !$zone) throw new InvalidArgumentException('Fecha de contexto sin zona horaria.');
        // Carbon uses microseconds; preserve the seventh SQL datetime2 digit separately.
        $fraction=str_pad($m[7] ?? '',7,'0');
        $base=sprintf('%s-%s-%sT%s:%s:%s%s',$m[1],$m[2],$m[3],$m[4],$m[5],$m[6],$m[8] ?? '');
        return CarbonImmutable::parse($base,$zone ?? 'UTC')->utc()->format('Y-m-d H:i:s').'.'.$fraction;
    }
}
