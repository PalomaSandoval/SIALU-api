<?php

namespace App\Integrations\Orion;

/** Institutional severity only; a real source value must be resolved before calling this. */
final class OrionSeverityClassifier
{
    public const VERSION = 2;

    public const PHRASES = [
        'is down' => 'Alta', 'está caído' => 'Alta', 'está caída' => 'Alta', 'no responde' => 'Alta',
        'is up' => 'Informativa', 'está activo' => 'Informativa', 'está activa' => 'Informativa', 'está disponible' => 'Informativa',
        'is warning' => 'Media', 'está en advertencia' => 'Media',
        'is critical' => 'Crítica', 'está crítico' => 'Crítica', 'está crítica' => 'Crítica', 'está en estado crítico' => 'Crítica',
        'has rebooted' => 'Media', 'se ha reiniciado' => 'Media', 'se reinició' => 'Media',
    ];

    public function classify(string $type, ?string $message): array
    {
        $name = ['1' => 'Alta', '5' => 'Informativa', '14' => 'Media', '100' => 'Desconocida', '5000' => 'Desconocida', '5001' => 'Informativa'][$type] ?? 'Desconocida';
        $rule = 'orion-tipo-evento';
        if (in_array($type, ['5000', '5001'], true) && $message !== null && trim($message, ' ') !== '') {
            $rule = 'orion-mensaje';
            $normalized = ' '.$this->normalize($message).' ';
            $matches = [];
            foreach (self::PHRASES as $phrase => $severity) {
                if (str_contains($normalized, ' '.$this->normalize($phrase).' ')) $matches[$severity] = true;
            }
            $name = count($matches) === 1 ? array_key_first($matches) : 'Desconocida';
        }

        return ['nombre' => $name, 'origen' => 'REGLA_INSTITUCIONAL', 'regla' => $rule, 'version' => self::VERSION];
    }

    private function normalize(string $message): string
    {
        return strtr(mb_strtolower($message, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            '.' => ' ', ',' => ' ', ';' => ' ', ':' => ' ', '!' => ' ', '?' => ' ', "\r" => ' ', "\n" => ' ',
        ]);
    }
}
