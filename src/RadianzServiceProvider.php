<?php

namespace Radianz\Laravel;

use GuzzleHttp\Client;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;

final class RadianzServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/radianz.php', 'radianz');
        $this->app->singleton(ErrorSender::class, fn ($app) => new ErrorSender(
            new Client(),
            (array) $app['config']->get('radianz'),
            (string) $app['config']->get('app.name'),
            (string) $app['config']->get('app.env'),
            $app->basePath(),
            (string) $app['config']->get('app.key'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
        }

        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event): void {
            if (isset(ErrorSender::LEVELS[$event->level])) {
                $this->app->make(ErrorSender::class)->onLog($event);
            }
        });
    }
}
