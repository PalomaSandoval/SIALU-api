<?php

namespace App\Integrations\PaloAlto;

use App\Contracts\EventParserInterface;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PaloAltoParser implements EventParserInterface
{
    public function parse(array $rawEvent): array
    {
        $text = function (string $key) use ($rawEvent) {
            if (! isset($rawEvent[$key])) {
                return null;
            }
            if (! is_scalar($rawEvent[$key])) {
                throw new InvalidArgumentException('Campo no escalar: '.$key);
            }
            $value = trim((string) $rawEvent[$key]);

            return $value === '' ? null : $value;
        };
        $type = strtoupper($text('type') ?? '');
        $serial = $text('serial');
        $id = $text('seqno') ?? $text('logid');
        if (! $serial || ! $id || ! in_array($type, ['THREAT', 'TRAFFIC'], true)) {
            throw new InvalidArgumentException('Se requieren serial, seqno/logid y tipo THREAT/TRAFFIC.');
        }
        // time_generated is the event time; high_res_timestamp is management-plane reception.
        $date = $text('time_generated');
        if (! $date) {
            throw new InvalidArgumentException('Falta fecha de evento.');
        }
        $hasOffset = preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $date);
        $timezone = config('sialu.paloalto.timezone');
        if (! $hasOffset && ! $timezone) {
            throw new InvalidArgumentException('Fecha sin offset: configura sialu.paloalto.timezone.');
        }
        if (! preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/', $date, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            throw new InvalidArgumentException('Fecha de evento inválida.');
        }
        $occurred = CarbonImmutable::parse($date, $timezone ?? 'UTC')->utc();
        $ports = [];
        foreach (['sport', 'dport'] as $key) {
            $value = $text($key);
            if ($value !== null && (! ctype_digit($value) || (int) $value > 65535)) {
                throw new InvalidArgumentException('Puerto inválido.');
            }
            $ports[$key] = $value === null ? null : (int) $value;
        }
        foreach (['src', 'dst'] as $key) {
            if ($text($key) !== null && filter_var($text($key), FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('IP inválida.');
            }
        }
        $event = [
            'external_id' => 'pa:v1:'.hash('sha256', json_encode([$serial, $type, $id], JSON_THROW_ON_ERROR)),
            'serial' => $serial, 'source_external_id' => $id,
            'occurred_at' => $occurred, 'received_at' => null,
            'event_type' => $type, 'event_subtype' => $text('subtype'),
            'severity' => $text('severity') === null ? null : strtolower($text('severity')),
            'action' => $text('action') === null ? null : strtolower($text('action')),
            'source_ip' => $text('src'), 'destination_ip' => $text('dst'),
            'source_port' => $ports['sport'], 'destination_port' => $ports['dport'],
            'protocol' => $text('proto'), 'source_zone' => $text('from'), 'destination_zone' => $text('to'),
            'application' => $text('app'), 'rule_name' => $text('rule'),
            'threat_name' => $text('threat_name') ?? $text('threatid'), 'threat_id' => $text('tid'),
            'threat_category' => $text('thr_category') ?? $text('category'),
        ];
        $limits = ['event_subtype' => 200, 'severity' => 100, 'action' => 100, 'protocol' => 20, 'source_zone' => 100,
            'destination_zone' => 100, 'application' => 100, 'rule_name' => 200, 'threat_name' => 300, 'threat_id' => 50, 'threat_category' => 100];
        foreach ($limits as $key => $limit) {
            if ($event[$key] !== null && strlen(mb_convert_encoding($event[$key], 'UTF-16LE', 'UTF-8')) / 2 > $limit) {
                throw new InvalidArgumentException('Campo excede capacidad SQL: '.$key);
            }
        }
        $fingerprint = $event;
        $fingerprint['occurred_at'] = $occurred->format('Y-m-d H:i:s.v');
        $event['fingerprint'] = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
        $event['timezone_requirement'] = $hasOffset ? null : $timezone;
        // Keep v1 semantic fingerprints compatible with already persisted events.
        $event['username'] = $text('srcuser');
        if ($event['username'] !== null && strlen(mb_convert_encoding($event['username'], 'UTF-16LE', 'UTF-8')) / 2 > 200) {
            throw new InvalidArgumentException('Usuario excede capacidad SQL.');
        }
        foreach (['bytes_sent' => 'bytes_sent', 'bytes_received' => 'bytes_received'] as $field => $key) {
            $value = $text($key);
            $number = $value === null ? null : (ltrim($value, '0') ?: '0');
            if ($value !== null && (! ctype_digit($value) || strlen($number) > 19 || (strlen($number) === 19 && strcmp($number, '9223372036854775807') > 0))) {
                throw new InvalidArgumentException('Contador de bytes inválido.');
            }
            $event[$field] = $number === null ? null : (int) $number;
        }
        $event['enrichment_fingerprint'] = hash('sha256', json_encode([$event['username'], $event['bytes_sent'], $event['bytes_received']], JSON_THROW_ON_ERROR));
        $event['context'] = (new PaloAltoContext)->parse($rawEvent);
        $event['raw_payload'] = $rawEvent;

        return $event;
    }
}
