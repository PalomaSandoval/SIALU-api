<?php
namespace App\Services;

use InvalidArgumentException;

class TrafficIpClassifier
{
    public function range(string $cidr): array
    {
        $parts = explode('/', $cidr);
        $packed = count($parts) === 2 ? @inet_pton($parts[0]) : false;
        if ($packed === false || !ctype_digit($parts[1])) throw new InvalidArgumentException('Escribe un rango válido, por ejemplo 192.0.2.0/24.');
        $prefix = (int) $parts[1];
        $bits = strlen($packed) * 8;
        if ($prefix > $bits) throw new InvalidArgumentException('El prefijo no corresponde a la versión IP.');
        $start = $end = '';
        for ($i = 0; $i < strlen($packed); $i++) {
            $mask = max(0, min(8, $prefix - $i * 8));
            $mask = $mask === 0 ? 0 : (255 << (8 - $mask)) & 255;
            $start .= chr(ord($packed[$i]) & $mask);
            $end .= chr((ord($packed[$i]) & $mask) | (255 ^ $mask));
        }
        return ['address' => inet_ntop($start), 'prefix' => $prefix, 'version' => $bits === 32 ? 4 : 6, 'start' => $start, 'end' => $end];
    }

    public function classify(?string $origin, ?string $destination, array $networks): array
    {
        $lookup = function (?string $ip) use ($networks) {
            $packed = $ip ? @inet_pton($ip) : false;
            if ($packed === false) return ['ambito' => 'DESCONOCIDO', 'red' => null];
            foreach ($networks as $network) {
                $range = $this->range($network['cidr']);
                if (strlen($packed) === strlen($range['start']) && strcmp($packed, $range['start']) >= 0 && strcmp($packed, $range['end']) <= 0) {
                    return ['ambito' => 'UACH', 'red' => $network['nombre']];
                }
            }
            // An unconfigured IP family must not be assumed external.
            $hasFamily = count(array_filter($networks, fn ($n) => $this->range($n['cidr'])['version'] === (strlen($packed) === 4 ? 4 : 6))) > 0;
            return ['ambito' => $hasFamily ? 'EXTERNA' : 'DESCONOCIDO', 'red' => null];
        };
        $o = $lookup($origin); $d = $lookup($destination);
        $sense = match ($o['ambito'].'/'.$d['ambito']) {
            'EXTERNA/UACH' => 'ENTRANTE', 'UACH/EXTERNA' => 'SALIENTE',
            'UACH/UACH' => 'INTERNO', 'EXTERNA/EXTERNA' => 'EXTERNO', default => 'SIN_CLASIFICAR',
        };
        return ['sentido' => $sense, 'origen' => $o, 'destino' => $d];
    }
}

