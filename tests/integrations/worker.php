<?php

// Stub the import command: these tests never contact APIs or SQL Server.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$root = sys_get_temp_dir().'/sialu-worker-test-'.bin2hex(random_bytes(8));
$app->useStoragePath($root);
$fake = new class extends Illuminate\Console\Command {
    protected $signature = 'sialu:sync {service} {--persist} {--since=} {--source-timezone} {--page-size=} {--max-pages=}';
    public array $calls = [];
    public bool $fail = false;
    public function handle(): int {
        $this->calls[] = ['service' => $this->argument('service'), 'date' => $this->option('since'), 'local' => $this->option('source-timezone')];
        return $this->fail ? 1 : 0;
    }
};
$kernel->registerCommand($fake);
$count = 0;
$assert = function ($test) use (&$count) { if (! $test) throw new RuntimeException('Worker test failed'); $count++; };
$run = fn ($args) => Illuminate\Support\Facades\Artisan::call('sialu:paloalto-watch', $args);
try {
    config(['sialu.persistence_enabled' => false]);
    $assert($run(['--persist'=>true,'--once'=>true]) === 1 && count($fake->calls) === 0);
    config(['sialu.persistence_enabled' => true]);
    $assert($run(['--once'=>true]) === 1 && count($fake->calls) === 0);
    $assert($run(['--persist'=>true,'--once'=>true,'--interval'=>0]) === 1);
    $lock = fopen($root.'/app/ingestion-private/worker/paloalto.lock','c');
    flock($lock, LOCK_EX | LOCK_NB);
    $assert($run(['--persist'=>true,'--once'=>true]) === 1 && count($fake->calls) === 0);
    flock($lock, LOCK_UN); fclose($lock);
    $assert($run(['--persist'=>true,'--once'=>true,'--since'=>'2026-10-22']) === 0);
    $assert(array_column($fake->calls,'service') === ['paloalto-traffic','paloalto-threat']);
    $assert($fake->calls[0]['date'] === '2026-10-22' && $fake->calls[0]['local'] === true);
    $fake->calls = []; $fake->fail = true;
    $assert($run(['--persist'=>true,'--once'=>true]) === 1 && count($fake->calls) === 1);
    $assert($run(['--stop'=>true]) === 0 && is_file($root.'/app/ingestion-private/worker/paloalto.stop'));
} finally {
    foreach (glob($root.'/app/ingestion-private/worker/*') ?: [] as $file) unlink($file);
    foreach (['/app/ingestion-private/worker','/app/ingestion-private','/app',''] as $dir) if (is_dir($root.$dir)) rmdir($root.$dir);
}
echo "PASS: {$count} worker checks, no network or SQL.\n";
