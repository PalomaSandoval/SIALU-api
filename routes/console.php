<?php

// Comandos propios registrados desde app/Console/Commands.

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Laravel 12 imprime "DONE" aunque un trabajo termine con código distinto de 0 (Task::render no reconoce
// el false que devuelve ScheduleRunCommand). Esta marca en stderr es la que el servicio registra como error;
// la excepción completa ya queda en laravel.log.
Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $failed) {
    fwrite(STDERR, 'SIALU-SCHEDULE-FAIL '.$failed->task->getSummaryForDisplay().' (código '.($failed->task->exitCode ?? '?').')'.PHP_EOL);
});

// Programador de Laravel. Lo ejecuta cada minuto el servicio de Windows SialuScheduler
// (scripts/windows-service); ver docs/programador.md. Orion estado de nodos, Orion eventos DOWN/UP y Palo Alto THREAT.
// TRAFFIC y sialu:paloalto-watch NO se programan (ver docs/integraciones.md).

$orionStatus = config('sialu.schedule.orion_status');
$lockMinutes = (int) ($orionStatus['lock_minutes'] ?? 10);
if ($lockMinutes < 1 || $lockMinutes > 60) {
    // No se lanza excepción: rompería todos los comandos artisan, no solo el programador.
    Log::warning('ORION_STATUS_SCHEDULE_LOCK_MINUTES fuera de 1-60; se usan 10.', ['valor' => $lockMinutes]);
    $lockMinutes = 10;
}

// Cada 5 minutos, menor que ORION_STATUS_MAX_AGE_MINUTES (15). Sin ejecuciones simultáneas: si la
// anterior sigue en curso esta se omite; si un proceso muere, el bloqueo caduca a los $lockMinutes.
Schedule::command('sialu:orion-status --persist')
    ->name('sialu-orion-status')
    ->description('Orion: instantánea de estado de nodos')
    ->everyFiveMinutes()
    ->withoutOverlapping($lockMinutes)
    ->when(fn () => (bool) config('sialu.schedule.orion_status.enabled'))
    // Salida del comando (instantánea o motivo del fallo). Si termina con error, schedule:run ya registra
    // la excepción en laravel.log e imprime FAIL, que el servicio SialuScheduler anota como error.
    ->appendOutputTo(storage_path('logs/orion-status-schedule.log'));

// Orion.Events DOWN/UP (movdEventos y movdDisponibilidad): continúa el cursor existente, nunca --since.
// Cada 5 minutos; 500 × 20 = 10 000 eventos por ejecución, suficiente para alcanzar un retraso de días
// (≈ 25 eventos/día medidos el 2026-11-11). Cada lectura completa guarda read_until en el cursor, que es
// lo que el tablero usa para presentar la actividad como actual (ORION_EVENTS_MAX_AGE_MINUTES).
$orionEventsLock = (int) (config('sialu.schedule.orion_events.lock_minutes') ?? 10);
if ($orionEventsLock < 1 || $orionEventsLock > 60) {
    Log::warning('ORION_EVENTS_SCHEDULE_LOCK_MINUTES fuera de 1-60; se usan 10.', ['valor' => $orionEventsLock]);
    $orionEventsLock = 10;
}
Schedule::command('sialu:sync orion --persist --page-size=500 --max-pages=20')
    ->name('sialu-orion-events')
    ->description('Orion: eventos DOWN/UP de nodos')
    ->everyFiveMinutes()
    ->withoutOverlapping($orionEventsLock)
    ->when(fn () => (bool) config('sialu.schedule.orion_events.enabled'))
    ->appendOutputTo(storage_path('logs/orion-events-schedule.log'));

// Palo Alto THREAT: continúa el cursor existente (sin --since; si no hubiera cursor el comando falla en lugar
// de crear uno). Cada 5 minutos y hasta page_size × max_pages filas por ejecución (500 × 20 = 10 000),
// para alcanzar el retraso acumulado tras una interrupción. Si el límite se alcanza, la ventana queda
// congelada en el cursor y la siguiente ejecución continúa donde quedó.
// Sin ejecuciones simultáneas: mutex de Laravel y, además, el flock no bloqueante del cursor
// (una ejecución manual concurrente hace fallar a la programada, y queda registrada como FAIL).
$threat = config('sialu.schedule.paloalto_threat');
$threatLimit = function (string $key, int $default, int $min, int $max) use ($threat) {
    $value = (int) ($threat[$key] ?? $default);
    if ($value < $min || $value > $max) {
        Log::warning("PALOALTO_THREAT_SCHEDULE_".strtoupper($key)." fuera de {$min}-{$max}; se usa {$default}.", ['valor' => $value]);

        return $default;
    }

    return $value;
};
$threatLock = $threatLimit('lock_minutes', 30, 1, 60);
$threatPageSize = $threatLimit('page_size', 500, 1, 5000);
$threatMaxPages = $threatLimit('max_pages', 20, 1, 1000);

Schedule::command("sialu:sync paloalto-threat --persist --page-size={$threatPageSize} --max-pages={$threatMaxPages}")
    ->name('sialu-paloalto-threat')
    ->description('Palo Alto: amenazas (THREAT)')
    ->everyFiveMinutes()
    ->withoutOverlapping($threatLock)
    ->when(fn () => (bool) config('sialu.schedule.paloalto_threat.enabled'))
    ->appendOutputTo(storage_path('logs/paloalto-threat-schedule.log'));
