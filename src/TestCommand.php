<?php

namespace Radianz\Laravel;

use Illuminate\Console\Command;
use Throwable;

final class TestCommand extends Command
{
    protected $signature = 'radianz:test';
    protected $description = 'Send a synthetic exception and check Radianz ingestion';

    public function handle(ErrorSender $sender): int
    {
        if (!$sender->configured()) {
            $this->error('Set RADIANZ_CLIENT_ID and RADIANZ_API_KEY, then refresh cached configuration.');
            return self::FAILURE;
        }
        try {
            $error = new RadianzTestException('Radianz Laravel installation test');
            report($error);
        } catch (Throwable) {
            $this->error('Laravel could not report the diagnostic. Check the exception handler and logging configuration.');
            return self::FAILURE;
        }
        if ($error->acceptedId === null) {
            $this->error($error->failure ?? 'Laravel did not forward this error. Check the channel level, exception exclusions and package discovery.');
            return self::FAILURE;
        }
        $this->info("Accepted by Radianz ($error->acceptedId). Look for RadianzTestException in your Error Inbox.");
        return self::SUCCESS;
    }
}
