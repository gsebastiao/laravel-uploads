<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Support;

use Gsebastiao\LaravelUploads\Exceptions\AuditPackageMissingException;

/**
 * Ponto único de verificação da integração opcional com o pacote
 * gsebastiao/laravel-auditable.
 *
 * O laravel-uploads NÃO depende do laravel-auditable no composer.json —
 * é uma integração opcional, ligada via config/uploads.php ("audit.enabled").
 * Esta classe existe para que a detecção "o pacote está instalado?" seja
 * feita da mesma forma em todos os pontos do código (trait do model,
 * service, service provider), em vez de espalhar `class_exists()` por aí.
 */
final class AuditSupport
{
    /**
     * Classe do trait `Auditable` do pacote gsebastiao/laravel-auditable.
     * Usamos o nome totalmente qualificado como string (em vez de importar
     * a classe com `use`) de propósito: importar obrigaria a classe a
     * existir em tempo de análise/autoload em cenários estritos, o que
     * contradiz a natureza opcional desta dependência.
     */
    public const AUDITABLE_TRAIT = 'Gsebastiao\\Auditable\\Concerns\\Auditable';

    /**
     * Classe base de opções de auditoria do pacote.
     */
    public const AUDIT_OPTIONS_CLASS = 'Gsebastiao\\Auditable\\Support\\AuditOptions';

    /**
     * Classe base do model de auditoria do pacote (usada apenas para
     * verificar disponibilidade; nunca instanciada directamente por aqui).
     */
    public const AUDIT_MODEL_CLASS = 'Gsebastiao\\Auditable\\Models\\Audit';

    /**
     * Override manual de packageInstalled(), usado exclusivamente pela
     * suite de testes deste pacote (ver tests/Unit/AuditIntegrationTest.php).
     *
     * Existe porque, na prática, gsebastiao/laravel-auditable é declarado em
     * require-dev (para podermos testar o caminho "pacote instalado"), então
     * ele está sempre presente no vendor/ durante os testes — não há forma
     * de "desinstalar" um pacote a meio da suite para testar o caminho
     * "pacote ausente". Este override permite simular os dois estados sem
     * depender da presença real do pacote no ambiente de teste.
     *
     * `null` (o valor por omissão) significa "sem override": usa a detecção
     * real via trait_exists()/class_exists(), o comportamento normal em
     * produção.
     */
    private static ?bool $packageInstalledOverride = null;

    private function __construct()
    {
        // Classe estática; não deve ser instanciada.
    }

    /**
     * Indica se o pacote gsebastiao/laravel-auditable está instalado
     * (isto é, se as suas classes estão disponíveis via autoload).
     */
    public static function packageInstalled(): bool
    {
        if (self::$packageInstalledOverride !== null) {
            return self::$packageInstalledOverride;
        }

        return trait_exists(self::AUDITABLE_TRAIT) && class_exists(self::AUDIT_OPTIONS_CLASS);
    }

    /**
     * SOMENTE PARA TESTES. Força packageInstalled() a devolver um valor fixo,
     * ignorando a detecção real via autoload. Chame resetOverride() no
     * tearDown do teste para restaurar o comportamento normal.
     */
    public static function overridePackageInstalled(bool $installed): void
    {
        self::$packageInstalledOverride = $installed;
    }

    /**
     * SOMENTE PARA TESTES. Remove o override definido por
     * overridePackageInstalled(), voltando à detecção real via autoload.
     */
    public static function resetOverride(): void
    {
        self::$packageInstalledOverride = null;
    }

    /**
     * Lê `uploads.audit.enabled` da configuração resolvida do pacote.
     *
     * @param array<string, mixed> $config Configuração de config/uploads.php.
     */
    public static function enabledIn(array $config): bool
    {
        return (bool) ($config['audit']['enabled'] ?? false);
    }

    /**
     * Garante que, se a auditoria estiver habilitada na configuração, o
     * pacote de auditoria está de facto instalado. Lança
     * AuditPackageMissingException caso contrário — falha rápida e alta,
     * em vez de degradar silenciosamente para "sem auditoria".
     *
     * @param array<string, mixed> $config Configuração de config/uploads.php.
     *
     * @throws AuditPackageMissingException
     */
    public static function assertAvailableIfEnabled(array $config): void
    {
        if (self::enabledIn($config) && ! self::packageInstalled()) {
            throw AuditPackageMissingException::make();
        }
    }

    /**
     * Resolve qual classe de model de arquivo o pacote deve usar:
     * a variante auditável (quando a auditoria está ligada e o pacote de
     * auditoria está instalado) ou o UploadFile normal, caso contrário.
     *
     * assertAvailableIfEnabled() já deve ter sido chamado antes disto (no
     * boot do service provider), então, ao chegar aqui, "audit.enabled" só
     * pode ser true se o pacote também estiver instalado — mas confirmamos
     * de novo por segurança, para nunca referenciar AuditableUploadFile
     * sem o pacote presente.
     *
     * @param array<string, mixed> $config Configuração de config/uploads.php.
     *
     * @return class-string<\Gsebastiao\LaravelUploads\Models\UploadFile>
     */
    public static function uploadFileModelClass(array $config): string
    {
        if (self::enabledIn($config) && self::packageInstalled()) {
            return \Gsebastiao\LaravelUploads\Models\Auditable\AuditableUploadFile::class;
        }

        return \Gsebastiao\LaravelUploads\Models\UploadFile::class;
    }
}
