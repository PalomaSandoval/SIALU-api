<?php
// Isolated checks: no API requests, SQL connections or real ingestion files.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Coverage\HistoryStart as H;
use App\Services\Coverage\IngestionCoverage as C;
use App\Services\Coverage\IngestionFreshness as F;
use App\Services\Coverage\StreamEvidence as E;
use Carbon\CarbonImmutable as T;

$checks = 0;
$assert = function ($ok, $label) use (&$checks) { if (! $ok) throw new RuntimeException('FAIL: '.$label); $checks++; };
$rejects = function (callable $action, $label) use (&$checks) {
    try { $action(); } catch (InvalidArgumentException|RuntimeException) { $checks++; return; }
    throw new RuntimeException('FAIL: '.$label);
};
$now = T::parse('2026-11-09 19:00:00', 'UTC');
$at = fn (string $ago) => $now->sub(DateInterval::createFromDateString($ago))->format('Y-m-d H:i:s');
$states = fn (array $days) => array_column($days, 'estado', 'fecha');

// 1. Freshness: no lots at all.
$f = F::evaluate(null, null, null, 60, $now);
$assert($f['estado'] === F::SIN_LOTES && $f['retrasada'] && ! $f['ultimo_intento_fallido'], 'No lots: sin_lotes and delayed');
$assert($f['ultima_consulta_exitosa'] === null && $f['ultimo_intento'] === null && $f['antiguedad_minutos'] === null, 'No lots: no dates invented');

// 2. Freshness: source queried successfully but without events.
$f = F::evaluate(['estado' => 'COMPLETADO', 'fecha' => $at('10 minutes')], $at('10 minutes'), null, 60, $now);
$assert($f['estado'] === F::AL_DIA && $f['ultimo_evento'] === null && $f['antiguedad_minutos'] === 10, 'Source without events can be current');

// 3. Freshness: last attempt failed after a recent success. Data stays as of the success.
$f = F::evaluate(['estado' => 'FALLIDO', 'fecha' => $at('5 minutes')], $at('20 minutes'), $at('25 minutes'), 60, $now);
$assert($f['estado'] === F::ULTIMO_INTENTO_FALLIDO && $f['ultimo_intento_fallido'] && ! $f['retrasada'], 'Failed last attempt is reported even inside threshold');
$assert($f['ultima_consulta_exitosa'] === $at('20 minutes') && $f['ultimo_intento']['fecha'] === $at('5 minutes'), 'Failed attempt date never replaces last success');

// 4. Freshness: failed attempt with no success ever.
$f = F::evaluate(['estado' => 'FALLIDO', 'fecha' => $at('1 hour')], null, null, 60, $now);
$assert($f['estado'] === F::ULTIMO_INTENTO_FALLIDO && $f['retrasada'] && $f['ultima_consulta_exitosa'] === null, 'Only a failed attempt: failed, delayed, no success');

// 5. Freshness: failed lot older than a later successful cursor read is not the last word.
$f = F::evaluate(['estado' => 'FALLIDO', 'fecha' => $at('2 hours')], F::latest($at('3 hours'), $at('10 minutes')), null, 60, $now);
$assert($f['estado'] === F::AL_DIA && ! $f['ultimo_intento_fallido'], 'Later successful read supersedes an older failure');

// 6. Freshness: success older than the threshold.
$f = F::evaluate(['estado' => 'COMPLETADO', 'fecha' => $at('2 hours')], $at('2 hours'), $at('2 hours'), 60, $now);
$assert($f['estado'] === F::RETRASADA && $f['retrasada'] && $f['antiguedad_minutos'] === 120, 'Old success is delayed');
$assert(F::latest(null, '2026-10-22 18:32:21.133', '', '2026-10-22T18:40:00+00:00') === '2026-10-22 18:40:00' && F::latest(null) === null, 'latest() ignores nulls and offsets');

// 7. Coverage: source without events and without evidence. Nothing is called lost or delayed.
$days = C::days(T::parse('2026-11-01'), T::parse('2026-11-09'), [], [], [], null, true, $now);
$assert(count($days) === 9 && array_unique(array_column($days, 'estado')) === [C::SIN_EVENTOS_NO_COMPROBADO], 'No events, no evidence: all unverified');
$assert(! in_array(C::HUECO_CONFIRMADO, array_column($days, 'estado'), true), 'Days without events are never auto-classified as loss');

// 8. Coverage, Orion-like: events until 2026-10-05, no lots, stream delayed.
$progress = T::parse('2026-10-05 16:35:53', 'UTC');
$s = $states(C::days(T::parse('2026-09-11'), T::parse('2026-10-08'), ['2026-09-11' => 86, '2026-09-12' => 60, '2026-09-14' => 50, '2026-10-05' => 16], [], [], $progress, true, $now));
$assert($s['2026-09-11'] === C::CON_EVENTOS && $s['2026-10-05'] === C::CON_EVENTOS, 'Days with events');
$assert($s['2026-09-13'] === C::SIN_EVENTOS_NO_COMPROBADO, 'A silent day before the progress is unverified, not a gap');
$assert($s['2026-10-06'] === C::INGESTA_RETRASADA && $s['2026-10-08'] === C::INGESTA_RETRASADA, 'Days after the last progress of a delayed stream');

// 9. Coverage: the same days are not delayed if the stream is current.
$s = $states(C::days(T::parse('2026-11-07'), T::parse('2026-11-09'), [], [], [], T::parse('2026-11-07 00:00:00'), false, $now));
$assert($s['2026-11-08'] === C::SIN_EVENTOS_NO_COMPROBADO, 'Not delayed: no retrasada state');

// 10. Coverage: a continuous read proves a silent day only with the late-arrival margin.
$window = [[T::parse('2026-10-20 00:00:00', 'UTC'), T::parse('2026-10-22 00:30:00', 'UTC')]];
$s = $states(C::days(T::parse('2026-10-19'), T::parse('2026-10-21'), [], $window, [], T::parse('2026-10-22 00:30:00'), false, $now));
$assert($s['2026-10-20'] === C::SIN_EVENTOS_CUBIERTO, 'Fully covered silent day');
$assert($s['2026-10-21'] === C::SIN_EVENTOS_NO_COMPROBADO, 'Covered without margin is not proof');
$assert($s['2026-10-19'] === C::SIN_EVENTOS_NO_COMPROBADO, 'Before the window: unverified');

// 11. Coverage: confirmed gaps, also inside a covered range; events still win but flag the gap.
$gap = [[T::parse('2026-10-20 06:00:00', 'UTC'), T::parse('2026-10-20 09:00:00', 'UTC')]];
$days = C::days(T::parse('2026-10-20'), T::parse('2026-10-20'), [], $window, $gap, null, false, $now);
$assert($days[0]['estado'] === C::HUECO_CONFIRMADO, 'Recorded gap beats coverage');
$days = C::days(T::parse('2026-10-20'), T::parse('2026-10-20'), ['2026-10-20' => 3], $window, $gap, null, false, $now);
$assert($days[0]['estado'] === C::CON_EVENTOS && $days[0]['hueco_parcial'], 'Events plus partial gap');
$assert(C::summary($days)[C::CON_EVENTOS] === 1 && array_sum(C::summary($days)) === 1, 'Summary counts');

// 12. Coverage limits.
$assert(count(C::days(T::parse('2026-11-08'), T::parse('2026-11-20'), [], [], [], null, false, $now)) === 2, 'Future days excluded');
$rejects(fn () => C::days(T::parse('2026-11-09'), T::parse('2026-11-01'), [], [], [], null, false, $now), 'Inverted range');

// 13. Orion retention: probably unrecoverable range, never a reconstruction.
$r = C::probablyUnrecoverable($progress, 30, $now);
$assert($r === ['desde' => '2026-10-05 16:35:53', 'hasta' => '2026-10-10 19:00:00'], 'Last stored event to now minus observed retention');
$assert(C::probablyUnrecoverable(T::parse('2026-11-01'), 30, $now) === null, 'Inside retention: recoverable');
$assert(C::probablyUnrecoverable($progress, null, $now) === null && C::probablyUnrecoverable(null, 30, $now) === null, 'No retention or progress: no estimate');

// 14. Cursor evidence: only what each cursor format really proves.
$assert(E::cursorEvidence('paloalto-threat', null)['estado'] === 'ausente' && E::cursorEvidence('paloalto-threat', null)['comprobado'] === [], 'Missing cursor proves nothing');
$assert(E::cursorEvidence('orion', false)['estado'] === 'invalido', 'Unreadable cursor proves nothing');
$pa = E::cursorEvidence('paloalto-traffic', ['completed_until' => '2026-10-22T18:31:00+00:00', 'initial_since' => '2026-10-22T05:00:00+00:00', 'window_end' => '2026-10-22T18:40:00+00:00', 'offset' => 10]);
$assert($pa['estado'] === 'presente' && count($pa['comprobado']) === 1 && $pa['exito'] === '2026-10-22 18:31:00', 'Palo Alto: completed windows only, not the open one');
$assert(E::cursorEvidence('paloalto-traffic', ['completed_until' => '2026-10-21T00:00:00+00:00', 'initial_since' => '2026-10-22T00:00:00+00:00'])['estado'] === 'invalido', 'Palo Alto: inverted cursor rejected');
$fresh = E::cursorEvidence('paloalto-threat', ['completed_until' => '2026-10-22T00:00:00+00:00', 'initial_since' => '2026-10-22T00:00:00+00:00']);
$assert($fresh['comprobado'] === [] && $fresh['exito'] === null, 'Palo Alto: cursor never advanced proves no read');
$or = E::cursorEvidence('orion', ['last_time' => '2026-10-05T16:35:40.333+00:00', 'last_id' => '991']);
$assert($or['comprobado'] === [] && $or['avance']->format('Y-m-d H:i') === '2026-10-05 16:35' && $or['exito'] === null, 'Orion events: progress without proven range');
$read = E::cursorEvidence('orion', ['last_time' => '2026-11-10T08:00:00.000+00:00', 'last_id' => '5', 'read_from' => '2026-11-09T19:28:25.463+00:00', 'read_until' => '2026-11-11T18:00:00.000+00:00']);
$assert(count($read['comprobado']) === 1 && $read['exito'] === '2026-11-11 18:00:00' && $read['avance']->format('Y-m-d H:i') === '2026-11-11 18:00', 'Orion events: read span proves range and last read');
$limit = E::cursorEvidence('orion', ['last_time' => '2026-11-11T19:00:00.000+00:00', 'last_id' => '9', 'read_from' => '2026-11-09T00:00:00+00:00', 'read_until' => '2026-11-11T18:00:00+00:00']);
$assert($limit['exito'] === '2026-11-11 18:00:00' && $limit['avance']->format('H:i') === '19:00', 'Orion events: page limit keeps the last complete read');
$assert(E::cursorEvidence('orion', ['last_time' => '2026-11-10T00:00:00+00:00', 'read_from' => '2026-11-11T00:00:00+00:00', 'read_until' => '2026-11-10T00:00:00+00:00'])['estado'] === 'invalido', 'Orion events: inverted read span rejected');
$al = E::cursorEvidence('orion-alerts', ['last_time' => '2026-11-09T18:00:00+00:00', 'last_id' => '0', 'initial_since' => '2026-11-01T00:00:00+00:00',
    'history_gaps' => [['desde' => '2026-11-02T00:00:00+00:00', 'hasta' => '2026-11-03T00:00:00+00:00']], 'last_reconciled_at' => '2026-11-09T18:01:00+00:00']);
$assert(count($al['huecos']) === 1 && count($al['comprobado']) === 1 && $al['exito'] === '2026-11-09 18:01:00', 'Alerts: gaps, read range and reconciliation');
$assert(E::cursorEvidence('orion-alerts', ['last_time' => '2026-11-09T18:00:00+00:00', 'initial_since' => '2026-11-01T00:00:00+00:00', 'history_gaps' => ['x']])['estado'] === 'invalido', 'Alerts: malformed gap rejected');
$assert(! array_key_exists('last_id', $al) && ! array_key_exists('offset', $pa), 'Cursor contents are not passed through');
$assert(E::streamsOf('paloalto') === ['paloalto-threat', 'paloalto-traffic'] && E::streamsOf('orion') === ['orion', 'orion-alerts'], 'Streams per adapter');

// 15. History start (SIALU_HISTORY_START): parsing.
$assert(H::parse(null) === null && H::parse('  ') === null, 'Unset history start: no limit');
$assert(H::parse('2026-11-09')->format('Y-m-d H:i:s e') === '2026-11-09 00:00:00 UTC', 'Date only: 00:00 UTC');
$assert(H::parse('2026-11-09T12:00:00-06:00')->format('Y-m-d H:i:s') === '2026-11-09 18:00:00', 'Offset converted to UTC');
$assert(H::parse('2026-11-09 12:00')->format('Y-m-d H:i:s') === '2026-11-09 12:00:00', 'No offset: UTC');
$rejects(fn () => H::parse('mañana'), 'Invalid history start rejected');
$rejects(fn () => H::parse('2026-02-30'), 'Impossible date rejected');

// Deleted history, as found in the database before the reset: Orion events until 05-10, a failed Palo Alto lot on 22-10,
// an alerts cursor with an October gap and stale Palo Alto cursors.
$start = T::parse('2026-11-09 12:00:00', 'UTC');
$oldAttempt = ['estado' => 'FALLIDO', 'fecha' => '2026-10-22 18:32:21'];
$oldAlerts = ['last_time' => '2026-10-20T00:00:00+00:00', 'last_id' => '0', 'initial_since' => '2026-10-01T00:00:00+00:00',
    'history_gaps' => [['desde' => '2026-10-10T00:00:00+00:00', 'hasta' => '2026-10-17T00:00:00+00:00']], 'last_reconciled_at' => '2026-10-20T00:05:00+00:00'];
$oldPa = ['completed_until' => '2026-10-22T18:31:00+00:00', 'initial_since' => '2026-10-22T05:00:00+00:00'];

// 16. Without a history start the old data is still reported (legacy behaviour unchanged).
$f = F::evaluate($oldAttempt, '2026-10-22 18:21:00', '2026-10-05 16:35:53', 60, $now);
$assert($f['estado'] === F::ULTIMO_INTENTO_FALLIDO && $f['inicio_historial'] === null, 'No history start: old failure still reported');
$assert(count(E::cursorEvidence('orion-alerts', $oldAlerts)['huecos']) === 1, 'No history start: October gap still reported');

// 17. Just reset: nothing since the history start, stale evidence everywhere -> "aún sin datos", no delay, no warnings.
$f = F::evaluate($oldAttempt, '2026-10-22 18:21:00', '2026-10-05 16:35:53', 60, $now, $start);
$assert($f['estado'] === F::AUN_SIN_DATOS && ! $f['retrasada'] && ! $f['ultimo_intento_fallido'], 'Reset: aún sin datos, not delayed, no failure');
$assert($f['ultimo_intento'] === null && $f['ultima_consulta_exitosa'] === null && $f['ultimo_evento'] === null && $f['antiguedad_minutos'] === null, 'Reset: no dates from deleted data');
$assert($f['inicio_historial'] === '2026-11-09 12:00:00', 'Reset: history start reported');
$assert(F::evaluate(null, null, null, 60, $now, $start)['estado'] === F::AUN_SIN_DATOS, 'Reset with nothing at all: aún sin datos');
$al = E::cursorEvidence('orion-alerts', $oldAlerts, $start);
$assert($al['estado'] === 'presente' && $al['huecos'] === [] && $al['comprobado'] === [] && $al['avance'] === null && $al['exito'] === null, 'Reset: October gap and stale alerts cursor ignored');
$pa = E::cursorEvidence('paloalto-threat', $oldPa, $start);
$assert($pa['comprobado'] === [] && $pa['avance'] === null && $pa['exito'] === null, 'Reset: stale Palo Alto cursor proves nothing');
$assert(E::cursorEvidence('orion', ['last_time' => '2026-10-05T16:35:40+00:00'], $start)['avance'] === null, 'Reset: stale Orion events cursor ignored');
$assert(C::probablyUnrecoverable(null, 30, $now) === null, 'Reset: no unrecoverable range from deleted data');
$days = C::days(T::parse('2026-09-11'), T::parse('2026-11-09'), ['2026-10-05' => 16], [], [[T::parse('2026-10-10'), T::parse('2026-10-17')]], T::parse('2026-10-05 16:35:53'), true, $now, 60, $start);
$assert(array_column($days, 'fecha') === ['2026-11-09'], 'Reset: no day before the history start is listed');
$assert(! in_array(C::HUECO_CONFIRMADO, array_column($days, 'estado'), true), 'Reset: October gap never shown');
$assert(C::days(T::parse('2026-11-01'), T::parse('2026-11-09'), [], [], [], null, false, $now, 60, T::parse('2026-11-10 00:00:00', 'UTC')) === [], 'History start in the future: no days');

// 18. After the first ingestion: alerts cursor started at the history start, first lot completed.
$firstAlerts = ['last_time' => '2026-11-09T18:30:00+00:00', 'last_id' => '0', 'initial_since' => '2026-11-09T12:00:00+00:00',
    'history_gaps' => [['desde' => '2026-10-10T00:00:00+00:00', 'hasta' => '2026-10-17T00:00:00+00:00']], 'last_reconciled_at' => '2026-11-09T18:31:00+00:00'];
$al = E::cursorEvidence('orion-alerts', $firstAlerts, $start);
$assert($al['huecos'] === [] && $al['comprobado'][0][0]->format('Y-m-d H:i') === '2026-11-09 12:00' && $al['exito'] === '2026-11-09 18:31:00', 'First ingestion: read range from the history start, old gap dropped');
$f = F::evaluate(['estado' => 'COMPLETADO', 'fecha' => '2026-11-09 18:31:00'], F::latest('2026-11-09 18:31:00', $al['exito']), '2026-11-09 18:10:00', 60, $now, $start);
$assert($f['estado'] === F::AL_DIA && $f['antiguedad_minutos'] === 29 && $f['ultimo_evento'] === '2026-11-09 18:10:00', 'First ingestion: current');
$days = C::days(T::parse('2026-09-11'), T::parse('2026-11-09'), ['2026-11-09' => 4], $al['comprobado'], $al['huecos'], $al['avance'], false, $now, 60, $start);
$assert($days === [['fecha' => '2026-11-09', 'estado' => C::CON_EVENTOS, 'eventos' => 4, 'hueco_parcial' => false]], 'First ingestion: coverage starts on the history start');
// Two days later, a silent partial first day is proven by a read from the history start (not from midnight).
$later = T::parse('2026-11-11 06:00:00', 'UTC');
$s = $states(C::days(T::parse('2026-11-01'), T::parse('2026-11-11'), [], [[$start, T::parse('2026-11-11 02:00:00', 'UTC')]], [], T::parse('2026-11-11 02:00:00', 'UTC'), false, $later, 60, $start));
$assert(array_keys($s) === ['2026-11-09', '2026-11-10', '2026-11-11'] && $s['2026-11-09'] === C::SIN_EVENTOS_CUBIERTO && $s['2026-11-10'] === C::SIN_EVENTOS_CUBIERTO, 'Partial first day covered from the history start');
// Delay counted only from the history start: last success 13:00, now 19:00, threshold 60 min.
$f = F::evaluate(['estado' => 'COMPLETADO', 'fecha' => '2026-11-09 13:00:00'], '2026-11-09 13:00:00', null, 60, $now, $start);
$assert($f['estado'] === F::RETRASADA && $f['antiguedad_minutos'] === 360, 'First ingestion then stalled: delayed');
$s = $states(C::days(T::parse('2026-11-01'), T::parse('2026-11-09'), [], [], [], T::parse('2026-11-09 13:00:00', 'UTC'), true, $now, 60, $start));
$assert($s === ['2026-11-09' => C::SIN_EVENTOS_NO_COMPROBADO], 'Delayed after first ingestion: the day of the progress is not delayed');
// Only a failed attempt since the start: delay measured from the start, never from deleted data.
$f = F::evaluate(['estado' => 'FALLIDO', 'fecha' => '2026-11-09 12:30:00'], '2026-10-22 18:21:00', '2026-10-05 16:35:53', 60, $now, $start);
$assert($f['estado'] === F::ULTIMO_INTENTO_FALLIDO && $f['retrasada'] && $f['ultima_consulta_exitosa'] === null && $f['ultimo_evento'] === null, 'Failed first attempt: failed, delayed since start, no old success');
$s = $states(C::days(T::parse('2026-11-01'), T::parse('2026-11-09'), [], [], [], null, true, $now, 60, $start));
$assert($s === ['2026-11-09' => C::INGESTA_RETRASADA], 'Failed first attempt: delayed from the history start');
$f = F::evaluate(['estado' => 'FALLIDO', 'fecha' => '2026-11-09 18:40:00'], null, null, 60, $now, T::parse('2026-11-09 18:30:00', 'UTC'));
$assert($f['estado'] === F::ULTIMO_INTENTO_FALLIDO && ! $f['retrasada'], 'Failed attempt inside the threshold after a recent start: not delayed');
$assert(C::probablyUnrecoverable(T::parse('2026-11-09 18:30:00', 'UTC'), 3, $now) === null, 'First ingestion: progress inside retention, no warning');

echo "PASS: {$checks} ingestion coverage and freshness checks; no SQL or API.\n";
