<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Unit;

use Gsebastiao\LaravelUploads\Contracts\UploadInterface;
use Gsebastiao\LaravelUploads\Exceptions\AuditPackageMissingException;
use Gsebastiao\LaravelUploads\Models\Auditable\AuditableUploadFile;
use Gsebastiao\LaravelUploads\Models\UploadFile;
use Gsebastiao\LaravelUploads\Support\AuditSupport;
use Gsebastiao\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;

/**
 * Cobre a integração opcional com gsebastiao/laravel-auditable:
 *  - com auditoria desligada (omissão), tudo funciona como antes, sem o
 *    pacote de auditoria precisar de estar instalado;
 *  - com auditoria ligada e o pacote instalado, os uploads passam a usar
 *    AuditableUploadFile e são de facto auditados;
 *  - com auditoria ligada e o pacote AUSENTE, a aplicação falha ao arrancar
 *    com AuditPackageMissingException — nunca finge auditar sem gravar nada.
 *
 * O pacote de auditoria está declarado em require-dev (necessário para testar
 * o "caminho feliz" com ele instalado). O cenário "pacote ausente" não pode
 * ser testado desinstalando o pacote real do vendor/ a meio da suite —
 * por isso simulamos a ausência via AuditPackageMissingTestSupport, uma
 * classe de teste que força AuditSupport::packageInstalled() a false.
 */
class AuditIntegrationTest extends TestCase
{
    protected function service(): UploadInterface
    {
        return $this->app->make(UploadInterface::class);
    }

    public function test_audit_disabled_by_default_uses_plain_upload_file(): void
    {
        $this->assertFalse(AuditSupport::enabledIn(config('uploads', [])));

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertInstanceOf(UploadFile::class, $model);
        $this->assertNotInstanceOf(AuditableUploadFile::class, $model);
    }

    public function test_audit_disabled_upload_file_audit_methods_are_safe_no_ops(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        // Não deve lançar excepção nem fazer nada perceptível: é seguro
        // chamar sempre, independentemente do estado da auditoria.
        $model->auditAction('qualquer_coisa', ['x' => 1]);
        $model->auditFailure('qualquer_coisa', new \RuntimeException('falha simulada'));

        $this->addToAssertionCount(1);
    }

    public function test_enabling_audit_with_package_installed_uses_auditable_model(): void
    {
        $this->assumePackageInstalled();
        config()->set('uploads.audit.enabled', true);
        $this->app->forgetInstance(UploadInterface::class);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertInstanceOf(AuditableUploadFile::class, $model);
    }

    public function test_enabling_audit_without_package_throws_clear_exception(): void
    {
        $this->assumePackageMissing();

        $this->expectException(AuditPackageMissingException::class);
        $this->expectExceptionMessageMatches('/gsebastiao\/laravel-auditable/');

        AuditSupport::assertAvailableIfEnabled(['audit' => ['enabled' => true]]);
    }

    public function test_audit_disabled_never_throws_even_without_package(): void
    {
        $this->assumePackageMissing();

        AuditSupport::assertAvailableIfEnabled(['audit' => ['enabled' => false]]);

        $this->addToAssertionCount(1);
    }

    public function test_model_class_resolution_matches_audit_state(): void
    {
        $this->assumePackageInstalled();

        $this->assertSame(
            UploadFile::class,
            AuditSupport::uploadFileModelClass(['audit' => ['enabled' => false]])
        );

        $this->assertSame(
            AuditableUploadFile::class,
            AuditSupport::uploadFileModelClass(['audit' => ['enabled' => true]])
        );
    }

    public function test_model_class_resolution_falls_back_when_package_missing_even_if_enabled(): void
    {
        $this->assumePackageMissing();

        // Rede de segurança: mesmo que 'enabled' seja true, sem o pacote
        // instalado a resolução nunca deve apontar para AuditableUploadFile
        // (isto nunca deveria acontecer em produção, pois o boot do
        // service provider já teria lançado AuditPackageMissingException
        // antes de chegar aqui — mas o método continua seguro por si só).
        $this->assertSame(
            UploadFile::class,
            AuditSupport::uploadFileModelClass(['audit' => ['enabled' => true]])
        );
    }

    /**
     * Força AuditSupport::packageInstalled() a reportar `true` durante o
     * teste, simulando o pacote instalado independentemente do ambiente
     * real do vendor/. Restaurado no tearDown().
     */
    protected function assumePackageInstalled(): void
    {
        AuditSupport::overridePackageInstalled(true);
    }

    /**
     * Força AuditSupport::packageInstalled() a reportar `false`, simulando
     * o pacote de auditoria ausente mesmo estando presente no vendor/ (via
     * require-dev). Restaurado no tearDown().
     */
    protected function assumePackageMissing(): void
    {
        AuditSupport::overridePackageInstalled(false);
    }

    protected function tearDown(): void
    {
        AuditSupport::resetOverride();

        parent::tearDown();
    }
}
