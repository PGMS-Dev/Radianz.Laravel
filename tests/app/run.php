<?php

// Laravel's uncaught-exception renderer may exit successfully in a CLI test fixture.
try {
    foreach (array_slice($argv, 1) as $test) {
        if (!in_array($test, ['check.php', 'stale-cache.php'], true)) {
            throw new InvalidArgumentException('Unknown integration check');
        }
        require __DIR__.'/'.$test;
    }
} catch (Throwable $failure) {
    fwrite(STDERR, $failure::class.': '.$failure->getMessage().PHP_EOL);
    exit(1);
}
