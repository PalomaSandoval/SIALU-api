<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

/**
 * Severity buckets of Palo Alto THREAT events, shared by the dashboard chart and the event query
 * so a chart count and the filtered list it links to always agree.
 * Buckets follow catcSeveridad.Nivel; any other level (Desconocida, No Aplica) or none is "sin_clasificar".
 */
final class ThreatSeverity
{
    public const UNCLASSIFIED = 'sin_clasificar';

    /** Key => catcSeveridad.Nivel, from the most to the least severe. */
    public const LEVELS = ['critica' => 5, 'alta' => 4, 'media' => 3, 'baja' => 2, 'informativa' => 1];

    public const LABELS = ['critica' => 'Crítica', 'alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja', 'informativa' => 'Informativa', self::UNCLASSIFIED => 'Sin clasificar'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function key(mixed $level): string
    {
        return array_search($level === null ? null : (int) $level, self::LEVELS, true) ?: self::UNCLASSIFIED;
    }

    /** Trimmed movdAmenazas.ThreatID (null when blank); requires joinThreat(). */
    public const THREAT_ID = "NULLIF(LTRIM(RTRIM(am.ThreatID)), N'')";

    /** Trimmed movdAmenazas.NombreAmenaza (null when blank); requires joinThreat(). */
    public const THREAT_NAME = "NULLIF(LTRIM(RTRIM(am.NombreAmenaza)), N'')";

    /** Adds movdAmenazas (one row per event at most, by its primary key) as "am". */
    public static function joinThreat(Builder $query, string $alias = 'v'): Builder
    {
        return $query->leftJoin('dbo.movdAmenazas as am', function ($join) use ($alias) {
            $join->on('am.IdEvento', '=', $alias.'.IdEvento')->on('am.FechaEvento', '=', $alias.'.FechaEvento');
        });
    }

    /**
     * Threat group key shared by the threat list and the event query:
     * "id:<ThreatID>" when the event has one, else "nombre:<NombreAmenaza>", else "ninguna".
     */
    public static function threatKey(?string $id, ?string $name): string
    {
        return $id !== null ? 'id:'.$id : ($name !== null ? 'nombre:'.$name : 'ninguna');
    }

    /** Filters by a threatKey(); requires joinThreat(). Returns false for a malformed key. */
    public static function filterThreat(Builder $query, string $key): bool
    {
        if ($key === 'ninguna') {
            $query->whereRaw(self::THREAT_ID.' IS NULL')->whereRaw(self::THREAT_NAME.' IS NULL');
        } elseif (str_starts_with($key, 'id:') && strlen($key) > 3) {
            $query->whereRaw(self::THREAT_ID.' = ?', [substr($key, 3)]);
        } elseif (str_starts_with($key, 'nombre:') && strlen($key) > 7) {
            $query->whereRaw(self::THREAT_ID.' IS NULL')->whereRaw(self::THREAT_NAME.' = ?', [substr($key, 7)]);
        } else {
            return false;
        }

        return true;
    }

    /** Filters by the Palo Alto subtype stored in AtributosExtra; null means events without one. */
    public static function filterSubtype(Builder $query, ?string $subtype, string $alias = 'v'): Builder
    {
        return $subtype === null ? $query->whereNull($alias.'.Subtipo') : $query->where($alias.'.Subtipo', $subtype);
    }

    /** Filters a query over NetworkZoneDiscovery::events() aliased as $alias. */
    public static function filter(Builder $query, string $key, string $alias = 'v'): Builder
    {
        $column = $alias.'.NivelSeveridad';

        return $key === self::UNCLASSIFIED
            ? $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, array_values(self::LEVELS)))
            : $query->where($column, self::LEVELS[$key]);
    }
}
