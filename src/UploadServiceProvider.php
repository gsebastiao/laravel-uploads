<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads;

use Gsebastiao\LaravelUploads\Contracts\UploadInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider do pacote laravel-uploads.
 *
 * Registra o binding do serviço, publica configuração e migrations,
 * e integra com o auto-discovery do Laravel.
 */
class UploadServiceProvider extends ServiceProvider
{
    /**
     * Registra bindings no container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/uploads.php', 'uploads');

        $this->app->singleton(UploadInterface::class, function ($app): UploadService {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('uploads', []);

            return new UploadService($config);
        });

        // Alias para resolução direta da classe concreta, se desejado.
        $this->app->alias(UploadInterface::class, UploadService::class);
    }

    /**
     * Executa ações de bootstrap (publicações e carregamento).
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/uploads.php' => $this->app->configPath('uploads.php'),
            ], 'uploads-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'uploads-migrations');

            // Tag agregadora conforme documentado no README.
            $this->publishes([
                __DIR__ . '/../config/uploads.php' => $this->app->configPath('uploads.php'),
                __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'uploads');
        }
    }

    /**
     * Serviços fornecidos por este provider (para deferimento).
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return [UploadInterface::class, UploadService::class];
    }
}
