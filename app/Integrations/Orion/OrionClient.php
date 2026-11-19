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
    private string $caFile;
    private bool $legacyTls;

    /** OpenSSL 3 excludes rsa_pkcs1_sha1 at its default level; Orion's SWIS certificate needs it. */
    public const LEGACY_TLS_CIPHERS = 'DEFAULT:@SECLEVEL=0';

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

        // Trust anchor for Orion's certificate when it is not issued by a CA in PHP's default bundle.
        $this->caFile = trim((string) config('services.orion.ca_file'));

        if ($this->caFile !== '' && ! is_readable($this->caFile)) {
            throw new RuntimeException(
                'ORION_CA_FILE no existe o no se puede leer.'
            );
        }

        // LOCAL DEVELOPMENT ONLY: never set ORION_LEGACY_TLS when deploying SIALU.
        $this->legacyTls = filter_var(
            config('services.orion.legacy_tls', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($this->legacyTls && ! app()->environment('local')) {
            throw new RuntimeException(
                'ORION_LEGACY_TLS solo se permite con APP_ENV=local; quitarlo de este entorno.'
            );
        }
    }

    /** A reset before TLS is established means Orion refused the handshake; credentials were never sent. */
    public static function connectionError(int $errno, int $appConnectMicroseconds, string $error): string
    {
        if (in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_RECV_ERROR, CURLE_SEND_ERROR], true) && $appConnectMicroseconds === 0) {
            return 'Orion cerró el handshake TLS antes de autenticar (cURL '.$errno.'). '
                .'Revisar el certificado del servicio SWIS de Orion.';
        }

        return 'Error al conectar con Orion: '.$error;
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
            + ($this->caFile !== '' ? [CURLOPT_CAINFO => $this->caFile] : [])
            + ($this->legacyTls ? [CURLOPT_SSL_CIPHER_LIST => self::LEGACY_TLS_CIPHERS] : [])
        );

        $response = curl_exec($ch);

        if ($response === false) {
            $message = self::connectionError(
                curl_errno($ch),
                (int) curl_getinfo($ch, CURLINFO_APPCONNECT_TIME_T),
                curl_error($ch)
            );

            curl_close($ch);

            throw new RuntimeException($message);
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
