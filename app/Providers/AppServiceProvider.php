<?php

namespace App\Providers;

use App\Contracts\LdapGateway;
use App\Services\AuthorizationService;
use App\Services\LdapService;
use App\Services\SessionManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LdapGateway::class, LdapService::class);
        $this->app->bind(\App\Contracts\Repositories\StagingRepositoryInterface::class, \App\Repositories\FileStagingRepository::class);
        $this->app->scoped(SessionManager::class);
        $this->app->scoped(AuthorizationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make('session')->extend('file', function ($app) {
            return new \App\Support\PersistentFileSessionHandler($app['files'], config('session.files'), 0);
        });
        foreach (['application', 'error', 'security', 'ldap', 'database', 'audit'] as $channel) {
            config()->set('logging.channels.sialu_'.$channel, [
                'driver' => 'daily',
                'path' => storage_path('logs/'.$channel.'.log'),
                'level' => config('logging.channels.daily.level', 'debug'),
                'days' => 30,
                'replace_placeholders' => true,
            ]);
        }
    }
}
