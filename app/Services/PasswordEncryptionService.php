<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\Logger;
use RuntimeException;
use Throwable;

/**
 * Servicio de cifrado reversible para contraseñas LDAP
 * almacenadas en SQL Server.
 *
 * IMPORTANTE:
 *
 * - Nunca registra contraseñas en texto claro.
 * - Nunca registra claves criptográficas.
 * - Utiliza AES-256-GCM.
 * - La clave maestra permanece fuera de la base de datos.
 */
final class PasswordEncryptionService
{
    private const VERSION = 'v1';

    private const DEFAULT_CIPHER = 'aes-256-gcm';

    private Config $config;

    private Logger $logger;

    public function __construct(
        Config $config,
        Logger $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;

        $this->validateEnvironment();
    }

    /**
     * Cifra una contraseña para almacenamiento en SQL Server.
     */
    public function encrypt(string $plainPassword): string
    {
        if ($plainPassword === '') {
            throw new RuntimeException(
                'No es posible cifrar una contraseña vacía.'
            );
        }

        try {
            $cipher = $this->cipher();
            $key = $this->encryptionKey();

            $ivLength = openssl_cipher_iv_length(
                $cipher
            );

            if ($ivLength === false || $ivLength < 1) {
                throw new RuntimeException(
                    'No fue posible determinar la longitud del IV.'
                );
            }

            $iv = random_bytes($ivLength);

            $tag = '';

            $ciphertext = openssl_encrypt(
                $plainPassword,
                $cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($ciphertext === false) {
                throw new RuntimeException(
                    'No fue posible cifrar la contraseña.'
                );
            }

            if ($tag === '') {
                throw new RuntimeException(
                    'El cifrado no generó una etiqueta de autenticación.'
                );
            }

            return implode(
                ':',
                [
                    self::VERSION,
                    base64_encode($iv),
                    base64_encode($tag),
                    base64_encode($ciphertext),
                ]
            );

        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->security(
                'Error durante el cifrado de una contraseña LDAP.',
                [
                    'exception' => get_class($exception),
                ]
            );

            throw new RuntimeException(
                'No fue posible proteger la contraseña.',
                0,
                $exception
            );
        }
    }

    /**
     * Descifra una contraseña almacenada en SQL Server.
     */
    public function decrypt(string $encryptedPassword): string
    {
        $encryptedPassword = trim(
            $encryptedPassword
        );

        if ($encryptedPassword === '') {
            throw new RuntimeException(
                'La contraseña cifrada está vacía.'
            );
        }

        try {
            $parts = explode(
                ':',
                $encryptedPassword
            );

            if (count($parts) !== 4) {
                throw new RuntimeException(
                    'El formato de la contraseña cifrada no es válido.'
                );
            }

            [
                $version,
                $ivEncoded,
                $tagEncoded,
                $ciphertextEncoded
            ] = $parts;

            if ($version !== self::VERSION) {
                throw new RuntimeException(
                    'La versión del cifrado no es compatible.'
                );
            }

            $iv = $this->decodeBase64(
                $ivEncoded
            );

            $tag = $this->decodeBase64(
                $tagEncoded
            );

            $ciphertext = $this->decodeBase64(
                $ciphertextEncoded
            );

            $expectedIvLength = openssl_cipher_iv_length(
                $this->cipher()
            );

            if (
                $expectedIvLength === false
                || strlen($iv) !== $expectedIvLength
            ) {
                throw new RuntimeException(
                    'El IV de la contraseña cifrada no es válido.'
                );
            }

            if (strlen($tag) !== 16) {
                throw new RuntimeException(
                    'La etiqueta de autenticación de la contraseña cifrada no es válida.'
                );
            }

            if ($ciphertext === '') {
                throw new RuntimeException(
                    'La contraseña cifrada no contiene datos.'
                );
            }

            $plainPassword = openssl_decrypt(
                $ciphertext,
                $this->cipher(),
                $this->encryptionKey(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($plainPassword === false) {
                throw new RuntimeException(
                    'No fue posible descifrar la contraseña.'
                );
            }

            return $plainPassword;

        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->security(
                'Error durante el descifrado de una contraseña LDAP.',
                [
                    'exception' => get_class($exception),
                ]
            );

            throw new RuntimeException(
                'No fue posible recuperar la contraseña.',
                0,
                $exception
            );
        }
    }

    /**
     * Obtiene y valida la clave maestra.
     */
    private function encryptionKey(): string
    {
        $encodedKey = trim(
            (string) $this->config->getRequired(
                'security.ldap_password_encryption.key_base64'
            )
        );

        $key = base64_decode(
            $encodedKey,
            true
        );

        if ($key === false) {
            throw new RuntimeException(
                'La clave de cifrado LDAP no contiene Base64 válido.'
            );
        }

        if (strlen($key) !== 32) {
            throw new RuntimeException(
                'La clave de cifrado LDAP debe contener exactamente 32 bytes.'
            );
        }

        return $key;
    }

    /**
     * Obtiene el algoritmo configurado.
     */
    private function cipher(): string
    {
        $cipher = strtolower(
            trim(
                (string) $this->config->get(
                    'security.ldap_password_encryption.cipher',
                    self::DEFAULT_CIPHER
                )
            )
        );

        if ($cipher !== self::DEFAULT_CIPHER) {
            throw new RuntimeException(
                'El algoritmo de cifrado configurado no está permitido.'
            );
        }

        return $cipher;
    }

    /**
     * Decodifica Base64 de manera estricta.
     */
    private function decodeBase64(
        string $value
    ): string {
        $decoded = base64_decode(
            $value,
            true
        );

        if ($decoded === false) {
            throw new RuntimeException(
                'El valor cifrado contiene Base64 inválido.'
            );
        }

        return $decoded;
    }

    /**
     * Valida los requisitos criptográficos.
     */
    private function validateEnvironment(): void
    {
        if (! extension_loaded('openssl')) {
            throw new RuntimeException(
                'La extensión OpenSSL de PHP no está habilitada.'
            );
        }

        if (
            ! in_array(
                self::DEFAULT_CIPHER,
                openssl_get_cipher_methods(),
                true
            )
        ) {
            throw new RuntimeException(
                'AES-256-GCM no está disponible en esta instalación de OpenSSL.'
            );
        }

        /*
         * Fuerza la validación de la configuración al crear el servicio.
         */
        $this->encryptionKey();
    }
}
