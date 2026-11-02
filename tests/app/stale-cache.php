<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Radianz\Laravel\RadianzServiceProvider;

require __DIR__.'/vendor/autoload.php';

// Simulate a deployed app whose cached configuration predates package discovery.
$cache = tempnam(__DIR__.'/bootstrap/cache', 'stale-config-');
try {
    $frameworkConfig = var_export(__DIR__.'/vendor/laravel/framework/config/*.php', true);
    file_put_contents($cache, '<?php $config = []; foreach (glob('.$frameworkConfig.') as $file) {
        $config[basename($file, ".php")] = require $file;
    } return $config;');
    putenv('APP_CONFIG_CACHE=bootstrap/cache/'.basename($cache));
    $app = Application::configure(basePath: __DIR__)->withExceptions()->create();
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (!$app->configurationIsCached() || !$app->getProvider(RadianzServiceProvider::class) || config('radianz') !== null) {
        throw new RuntimeException('Expected a discovered package with an old configuration cache');
    }
    report(new RuntimeException('An application error while configuration is cached'));
    if ($kernel->call('radianz:test') !== 1 || !str_contains($kernel->output(), 'RADIANZ_API_KEY')) {
        throw new RuntimeException('Missing credentials should produce an actionable diagnostic');
    }
    echo 'PASS stale configuration cache'.PHP_EOL;
} finally {
    unlink($cache);
}
