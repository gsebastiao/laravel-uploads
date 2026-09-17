<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Exceptions;

use RuntimeException;

/**
 * Lançada quando `uploads.audit.enabled` está a `true` no config, mas o
 * pacote gsebastiao/laravel-auditable não está instalado na aplicação.
 *
 * A auditoria é uma integração opcional: o laravel-uploads nunca declara
 * gsebastiao/laravel-auditable como dependência obrigatória no composer.json
 * (isso obrigaria todos os utilizadores do pacote a instalá-lo, mesmo quem
 * nunca vai activar auditoria). Em vez disso, verificamos em runtime se as
 * classes do pacote de auditoria existem antes de as utilizar. Se o utilizador
 * ligou a opção sem ter o pacote instalado, falhamos cedo e alto — o
 * comportamento incorreto seria enganar o utilizador para pensar que a
 * auditoria está a funcionar quando na realidade nada está a ser gravado.
 */
class AuditPackageMissingException extends RuntimeException
{
    /**
     * Cria a exceção padrão, instruindo como corrigir a situação.
     */
    public static function make(): self
    {
        return new self(
            'A auditoria está habilitada em config/uploads.php ("audit.enabled" => true), ' .
            'mas o pacote gsebastiao/laravel-auditable não está instalado. ' .
            'Instale-o com "composer require gsebastiao/laravel-auditable", publique a sua ' .
            'configuração e migration ("php artisan vendor:publish --tag=auditable-config" e ' .
            '"php artisan vendor:publish --tag=auditable-migrations", depois "php artisan migrate"), ' .
            'ou desligue "audit.enabled" em config/uploads.php caso não deseje auditar os uploads.'
        );
    }
}
