<?php

declare(strict_types=1);

namespace Prism\Relay;

use Illuminate\Support\ServiceProvider;

class RelayServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/relay.php', 'relay'
        );
        $this->app->bind('relay', fn (): \Prism\Relay\RelayFactory => new RelayFactory);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/relay.php' => config_path('relay.php'),
            ], 'relay-config');
        }
    }
}
