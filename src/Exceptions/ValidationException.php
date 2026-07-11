<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Exceptions;

use RuntimeException;

/**
 * Lançada quando um arquivo não passa nas regras de validação
 * (extensão/MIME não permitido ou tamanho acima do limite).
 */
class ValidationException extends RuntimeException
{
    /**
     * Cria uma exceção para tipo de arquivo não permitido.
     */
    public static function invalidMime(string $ext, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self("Tipo de arquivo não permitido: '{$ext}'. Permitidos: {$list}.");
    }

    /**
     * Cria uma exceção para arquivo acima do tamanho máximo.
     *
     * @param int $sizeKb   Tamanho do arquivo em KB.
     * @param int $maxKb    Limite configurado em KB.
     */
    public static function tooLarge(int $sizeKb, int $maxKb): self
    {
        return new self("Arquivo excede o tamanho máximo: {$sizeKb} KB (limite: {$maxKb} KB).");
    }

    /**
     * Cria uma exceção para arquivo ausente ou corrompido.
     */
    public static function invalidFile(): self
    {
        return new self('O arquivo enviado é inválido ou não pôde ser lido.');
    }
}
