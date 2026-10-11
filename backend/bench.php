<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function t(string $label, callable $fn): void {
    $s = microtime(true);
    try { $fn(); $ok = 'ok'; } catch (\Throwable $e) { $ok = 'ERR '.$e->getMessage(); }
    printf("%-18s %6.1f ms  %s\n", $label, (microtime(true) - $s) * 1000, $ok);
}

t('db select 1', fn () => \Illuminate\Support\Facades\DB::selectOne('select 1'));
t('cache get', fn () => \Illuminate\Support\Facades\Cache::get('x'));
t('queue size', fn () => app(\Illuminate\Contracts\Queue\Factory::class)->connection()->size());
t('orders count', fn () => \App\Modules\Order\Models\Order::count());
