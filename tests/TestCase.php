<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests;

use Gsebastiao\LaravelUploads\Support\AuditSupport;
use Gsebastiao\LaravelUploads\UploadServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Caso de teste base: registra o provider, roda migrations em memória
 * e configura um disco de armazenamento falso.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Tabela auxiliar para os testes de relação polimórfica.
        Schema::create('test_users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
        });

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        // Rede de segurança de isolamento: garante que um teste de
        // AuditIntegrationTest que define um override de
        // AuditSupport::packageInstalled() nunca vaza para outra classe de
        // teste, mesmo que o seu próprio tearDown() não corra por algum
        // motivo (ex.: falha a meio do teste).
        AuditSupport::resetOverride();

        parent::tearDown();
    }

    /**
     * Registra o service provider do pacote.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [UploadServiceProvider::class];
    }

    /**
     * Registra o alias do facade.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Upload' => \Gsebastiao\LaravelUploads\Facades\Upload::class];
    }

    /**
     * Ambiente de teste: banco em memória e disco público.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('uploads.disk', 'public');

        // Permite que UploadFile::uploader() resolva para TestUser nos testes,
        // em vez do App\Models\User por omissão (que não existe aqui).
        $app['config']->set('auth.providers.users.model', \Gsebastiao\LaravelUploads\Tests\Fixtures\TestUser::class);

        // Guard adicional, usado no teste que confirma que 'auth_guard' é
        // respeitado (não é o guard 'web' por omissão do Testbench).
        $app['config']->set('auth.guards.custom', ['driver' => 'session', 'provider' => 'users']);
    }
}
