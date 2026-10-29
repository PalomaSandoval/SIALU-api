<?php

namespace App\Integrations\Orion;

use RuntimeException;

class OrionClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private bool $verifySsl;

    public function __construct()
    {
        $this->host = trim(
            (string) config('services.orion.host')
        );

        $this->port = (int) config(
            'services.orion.port',
            17778
        );

        $this->username = trim(
            (string) config('services.orion.username')
        );

        $this->password = (string) config(
            'services.orion.password'
        );

        $this->verifySsl = filter_var(
            config(
                'services.orion.ssl_verify',
                true
            ),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($this->host === '') {
            throw new RuntimeException(
                'ORION_HOST no está configurado.'
            );
        }

        if ($this->username === '') {
            throw new RuntimeException(
                'ORION_USERNAME no está configurado.'
            );
        }
    }

    public function query(string $swql): array
    {
        $url = sprintf(
            'https://%s:%d/SolarWinds/InformationService/v3/Json/Query',
            $this->host,
            $this->port
        );

        $payload = json_encode(
            [
                'query' => $swql,
            ],
            JSON_THROW_ON_ERROR
        );

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException(
                'No fue posible iniciar cURL.'
            );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,

                CURLOPT_USERPWD =>
                    $this->username .
                    ':' .
                    $this->password,

                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],

                CURLOPT_POSTFIELDS => $payload,

                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,

                CURLOPT_SSL_VERIFYPEER =>
                    $this->verifySsl,

                CURLOPT_SSL_VERIFYHOST =>
                    $this->verifySsl ? 2 : 0,

                CURLOPT_SSLVERSION =>
                    CURL_SSLVERSION_TLSv1_2,

            ]
        );

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);

            curl_close($ch);

            throw new RuntimeException(
                'Error al conectar con Orion: ' .
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
                'Orion respondió con HTTP ' .
                $httpCode .
                '.'
            );
        }

        $data = json_decode(
            $response,
            true
        );

        if (!is_array($data)) {
            throw new RuntimeException(
                'La respuesta de Orion no es JSON válido.'
            );
        }

        if (! isset($data['results']) || ! is_array($data['results']) || ! array_is_list($data['results'])) {
            throw new RuntimeException('La respuesta de Orion no contiene una lista results válida.');
        }

        return $data['results'];
    }
}