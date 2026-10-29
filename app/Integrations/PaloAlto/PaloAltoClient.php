<?php

namespace App\Integrations\PaloAlto;

use RuntimeException;
use SimpleXMLElement;

class PaloAltoClient
{
    private string $host;

    private int $port;

    private string $apiKey;

    private bool $verifySsl;

    public function __construct()
    {
        $this->host = trim(
            (string) config('services.paloalto.host')
        );

        $this->port = (int) config(
            'services.paloalto.port',
            443
        );

        $this->apiKey = trim(
            (string) config('services.paloalto.api_key')
        );

        $this->verifySsl = filter_var(
            config(
                'services.paloalto.ssl_verify',
                true
            ),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($this->host === '') {
            throw new RuntimeException(
                'PALOALTO_HOST no está configurado.'
            );
        }

        if ($this->apiKey === '') {
            throw new RuntimeException(
                'PALOALTO_API_KEY no está configurado.'
            );
        }
    }

    /**
     * Ejecuta una petición genérica contra la XML API.
     */
    public function request(array $params): SimpleXMLElement
    {
        $url = sprintf(
            'https://%s:%d/api/',
            $this->host,
            $this->port
        );

        $ch = curl_init();

        if ($ch === false) {
            throw new RuntimeException(
                'No fue posible iniciar cURL.'
            );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_POSTFIELDS => http_build_query($params),

                CURLOPT_HTTPHEADER => [
                    'Accept: application/xml',
                    'X-PAN-KEY: '.$this->apiKey,
                ],

                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 90,

                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,

                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            ]
        );

        $response = curl_exec($ch);

        if ($response === false) {

            $error = curl_error($ch);

            curl_close($ch);

            throw new RuntimeException(
                'Error conectando con Palo Alto: '.
                $error
            );
        }

        $httpCode = curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {

            throw new RuntimeException(
                'Palo Alto respondió con HTTP '.
                $httpCode.
                '.'
            );
        }

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string(
            $response
        );

        if ($xml === false) {

            throw new RuntimeException(
                'La respuesta de Palo Alto no es XML válido.'
            );
        }

        $status = (string) (
            $xml['status'] ?? ''
        );

        if ($status !== 'success') {

            $mensaje = 'Error desconocido';

            if (isset($xml->msg->line)) {

                $mensaje = trim(
                    (string) $xml->msg->line
                );

            } elseif (isset($xml->msg)) {

                $mensaje = trim(
                    (string) $xml->msg
                );

            } elseif (isset($xml->result->msg)) {

                $mensaje = trim(
                    (string) $xml->result->msg
                );
            }

            throw new RuntimeException(
                'La API de Palo Alto respondió con error: '.
                $mensaje
            );
        }

        return $xml;
    }

    /**
     * Inicia una consulta de logs.
     */
    public function iniciarLogJob(
        string $tipo,
        string $query = '',
        int $cantidad = 100,
        int $skip = 0,
        string $direction = 'backward'
    ): int {
        $params = [
            'type' => 'log',
            'log-type' => $tipo,
            'nlogs' => $cantidad,
            'skip' => $skip,
            'dir' => $direction,
        ];

        if ($query !== '') {
            $params['query'] = $query;
        }

        $xml = $this->request($params);

        if (! isset($xml->result->job)) {

            throw new RuntimeException(
                'Palo Alto no devolvió un job-id.'
            );
        }

        return (int) $xml->result->job;
    }

    /**
     * Consulta un job de logs existente.
     */
    public function obtenerLogJob(
        int $jobId
    ): SimpleXMLElement {
        return $this->request([
            'type' => 'log',
            'action' => 'get',
            'job-id' => $jobId,
        ]);
    }

    /**
     * Espera hasta que termine un job de logs.
     */
    public function esperarLogJob(
        int $jobId,
        int $intentos = 15,
        int $esperaSegundos = 1
    ): SimpleXMLElement {
        for ($i = 0; $i < $intentos; $i++) {

            $xml = $this->obtenerLogJob(
                $jobId
            );

            $status = (string) (
                $xml->result->job->status
                ?? ''
            );

            if ($status === 'FIN') {
                return $xml;
            }

            sleep($esperaSegundos);
        }

        throw new RuntimeException(
            "El job {$jobId} no terminó dentro del tiempo esperado."
        );
    }
}
