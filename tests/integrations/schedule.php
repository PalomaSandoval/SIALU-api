<?php
// Isolated checks of the Laravel Scheduler definition: no Orion, SQL or command execution.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (! $ok) throw new RuntimeException('FAIL: '.$label); $checks++; };

$events = $app->make(Schedule::class)->events();
$orion = array_values(array_filter($events, fn ($e) => str_contains((string) $e->command, 'sialu:orion-status')));
$assert(count($orion) === 1, 'Exactly one orion-status job (no duplicates)');
$e = $orion[0];

// Other jobs may be scheduled in the future, but each command at most once and each with its own mutex,
// so a new job can neither duplicate nor block orion-status.
$commands = array_map(fn ($ev) => trim(preg_replace('/^.*artisan\S*\s+/', '', (string) $ev->command)), $events);
$assert(count($commands) === count(array_unique($commands)), 'No scheduled command is duplicated');
$mutexes = array_map(fn ($ev) => $ev->mutexName(), $events);
$assert(count($mutexes) === count(array_unique($mutexes)), 'Each scheduled job has its own mutex');

$assert(str_ends_with(trim((string) $e->command), 'sialu:orion-status --persist'), 'Runs with --persist');
$assert($e->expression === '*/5 * * * *', 'Every five minutes');
$assert($e->withoutOverlapping === true, 'Without overlapping');
$assert($e->expiresAt === (int) config('sialu.schedule.orion_status.lock_minutes'), 'Lock expiry from config');
$assert($e->expiresAt >= 1 && $e->expiresAt <= 60, 'Lock expiry within 1-60');
$assert($e->runInBackground === false, 'Foreground: exit code is observed');
$assert(str_ends_with(str_replace('\\', '/', (string) $e->output), 'storage/logs/orion-status-schedule.log') && $e->shouldAppendOutput, 'Output appended to log');

// A failed job must be visible to the service (Laravel prints DONE even on failure).
$assert(Illuminate\Support\Facades\Event::hasListeners(Illuminate\Console\Events\ScheduledTaskFailed::class), 'Failure marker listener registered');

// Frequency must stay below the dashboard's maximum snapshot age.
$assert(5 < (int) config('sialu.orion.status_max_age_minutes'), 'Interval below ORION_STATUS_MAX_AGE_MINUTES');

// The .env flag switches the job on and off without editing code.
$original = config('sialu.schedule.orion_status.enabled');
config(['sialu.schedule.orion_status.enabled' => false]);
$assert(! $e->filtersPass($app), 'Disabled by ORION_STATUS_SCHEDULE_ENABLED=false');
config(['sialu.schedule.orion_status.enabled' => true]);
$assert($e->filtersPass($app), 'Enabled by ORION_STATUS_SCHEDULE_ENABLED=true');
config(['sialu.schedule.orion_status.enabled' => $original]);

// The mutex really blocks a second run, and releasing it allows the next one.
// shouldSkipDueToOverlapping() is what schedule:run calls before starting: it TAKES the mutex when free.
$assert(Cache::store()->getStore() instanceof LockProvider, 'Cache store supports atomic locks');
$e->mutex->forget($e);
try {
    $assert(! $e->mutex->exists($e), 'Mutex starts free');
    $assert(! $e->shouldSkipDueToOverlapping(), 'First run starts and takes the mutex');
    $assert($e->mutex->exists($e), 'Mutex held while the first run is in progress');
    $assert($e->shouldSkipDueToOverlapping(), 'Concurrent run is skipped');
    config(['sialu.schedule.orion_status.enabled' => true]);
    $assert(! $e->filtersPass($app), 'Held mutex also fails the schedule filters');
    config(['sialu.schedule.orion_status.enabled' => $original]);
    $e->mutex->forget($e); // what Event::run does when the command ends
    $assert(! $e->mutex->exists($e), 'Mutex released when the run ends');
    $assert(! $e->shouldSkipDueToOverlapping(), 'Next run starts after release');
} finally {
    $e->mutex->forget($e);
    config(['sialu.schedule.orion_status.enabled' => $original]);
}

// Palo Alto THREAT: only `sialu:sync paloalto-threat`, never TRAFFIC, paloalto-watch or --since.
foreach ($commands as $command) {
    $assert(! str_contains($command, 'paloalto-traffic'), 'TRAFFIC is not scheduled: '.$command);
    $assert(! str_contains($command, 'paloalto-watch'), 'paloalto-watch is not scheduled: '.$command);
    $assert(! str_contains($command, '--since'), 'No scheduled job passes --since: '.$command);
}
$threat = array_values(array_filter($events, fn ($ev) => str_contains((string) $ev->command, 'sialu:sync paloalto-threat')));
$assert(count($threat) === 1, 'Exactly one paloalto-threat job');
$t = $threat[0];
$cfg = config('sialu.schedule.paloalto_threat');
$assert(str_ends_with(trim((string) $t->command), "sialu:sync paloalto-threat --persist --page-size={$cfg['page_size']} --max-pages={$cfg['max_pages']}"), 'THREAT command, --persist and limits from config');
$assert($t->expression === '*/5 * * * *', 'THREAT every five minutes');
$assert($t->withoutOverlapping === true && $t->expiresAt === (int) $cfg['lock_minutes'], 'THREAT without overlapping, lock from config');
$assert($t->runInBackground === false, 'THREAT in foreground: exit code is observed');
$assert(str_ends_with(str_replace('\\', '/', (string) $t->output), 'storage/logs/paloalto-threat-schedule.log') && $t->shouldAppendOutput, 'THREAT output appended to its own log');
$assert($t->mutexName() !== $e->mutexName(), 'THREAT and Orion do not share a mutex');
// Catch-up capacity: one run must read far more than arrives in 5 minutes (≈ 2 THREAT/min observed),
// so a backlog after an interruption shrinks every run.
$assert($cfg['page_size'] * $cfg['max_pages'] >= 100 * 5 * 2, 'One run reads at least 100× the 5-minute arrival');
$assert(env('PALOALTO_THREAT_SCHEDULE_ENABLED') !== null || $cfg['enabled'] === false, 'Disabled unless explicitly enabled in .env');
$originalThreat = $cfg['enabled'];
config(['sialu.schedule.paloalto_threat.enabled' => false]);
$assert(! $t->filtersPass($app), 'THREAT disabled by PALOALTO_THREAT_SCHEDULE_ENABLED=false');
config(['sialu.schedule.paloalto_threat.enabled' => true]);
$assert($t->filtersPass($app), 'THREAT enabled by PALOALTO_THREAT_SCHEDULE_ENABLED=true');
$t->mutex->forget($t);
try {
    $assert(! $t->shouldSkipDueToOverlapping(), 'First THREAT run takes the mutex');
    $assert($t->shouldSkipDueToOverlapping(), 'Concurrent THREAT run is skipped');
    $assert(! $e->mutex->exists($e), 'A running THREAT job does not block Orion');
    $t->mutex->forget($t);
    $assert(! $t->shouldSkipDueToOverlapping(), 'Next THREAT run starts after release');
} finally {
    $t->mutex->forget($t);
    config(['sialu.schedule.paloalto_threat.enabled' => $originalThreat]);
}

// Orion.Events DOWN/UP: `sialu:sync orion` on the existing cursor (never --since), its own mutex and log.
$orionEvents = array_values(array_filter($events, fn ($ev) => preg_match('/sialu:sync orion(\s|$)/', (string) $ev->command)));
$assert(count($orionEvents) === 1, 'Exactly one orion events job');
$o = $orionEvents[0];
$ocfg = config('sialu.schedule.orion_events');
$assert(str_ends_with(trim((string) $o->command), 'sialu:sync orion --persist --page-size=500 --max-pages=20'), 'Orion events: --persist and fixed limits');
$assert($o->expression === '*/5 * * * *', 'Orion events every five minutes');
$assert($o->withoutOverlapping === true && $o->expiresAt === (int) $ocfg['lock_minutes'], 'Orion events without overlapping, lock from config');
$assert($o->runInBackground === false, 'Orion events in foreground: exit code is observed');
$assert(str_ends_with(str_replace('\\', '/', (string) $o->output), 'storage/logs/orion-events-schedule.log') && $o->shouldAppendOutput, 'Orion events output appended to its own log');
$assert($o->mutexName() !== $e->mutexName() && $o->mutexName() !== $t->mutexName(), 'Orion events has its own mutex');
// Each run's read must end well inside the dashboard's maximum age for current activity.
$assert(5 < (int) config('sialu.orion.events_max_age_minutes'), 'Interval below ORION_EVENTS_MAX_AGE_MINUTES');
$assert(env('ORION_EVENTS_SCHEDULE_ENABLED') !== null || $ocfg['enabled'] === false, 'Orion events disabled unless explicitly enabled in .env');
$originalEvents = $ocfg['enabled'];
try {
    config(['sialu.schedule.orion_events.enabled' => false]);
    $assert(! $o->filtersPass($app), 'Orion events disabled by ORION_EVENTS_SCHEDULE_ENABLED=false');
} finally {
    config(['sialu.schedule.orion_events.enabled' => $originalEvents]);
}

echo "PASS: {$checks} schedule checks; no network or SQL.\n";
