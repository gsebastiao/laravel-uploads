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

            // Plugin JS de referência — destino configurável via
            // config('uploads.assets_path'), omissão public/assets/js.
            $assetsPath = $this->app->publicPath(
                $this->app['config']->get('uploads.assets_path', 'assets/js')
            );

            $this->publishes([
                __DIR__ . '/Plugin/upload-capture.init.js' => $assetsPath . '/upload-capture.js',
            ], 'uploads-assets');

            // Migration de upgrade — só relevante para quem já tinha a
            // v1.0.0 instalada (a migration original de instalações novas,
            // a partir da v1.1.0, já inclui category/uploaded_by; ver
            // CHANGELOG.md). Fica DE FORA da tag agregadora 'uploads' de
            // propósito — instalações novas não devem publicar isto.
            // publishesMigrations() dá-lhe um timestamp fresco automaticamente,
            // para correr depois de qualquer migration já existente.
            $this->publishesMigrations([
                __DIR__ . '/../database/migrations-upgrades/add_category_and_uploaded_by_to_uploads_files_table.php.stub' => $this->app->databasePath('migrations/add_category_and_uploaded_by_to_uploads_files_table.php'),
            ], 'uploads-upgrade-1.2.0');

            // Tag agregadora conforme documentado no README. Propositadamente
            // NÃO inclui uploads-upgrade-1.2.0 (ver nota acima).
            $this->publishes([
                __DIR__ . '/../config/uploads.php' => $this->app->configPath('uploads.php'),
                __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
                __DIR__ . '/Plugin/upload-capture.init.js' => $assetsPath . '/upload-capture.js',
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
