<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Exceptions;

use RuntimeException;

/**
 * Lançada quando ocorre uma falha durante o processo de upload
 * (escrita em disco, decodificação base64, geração de thumbnail, etc.).
 */
class UploadException extends RuntimeException
{
    /**
     * Cria uma exceção para falha genérica de upload.
     */
    public static function forReason(string $reason): self
    {
        return new self("Falha no upload: {$reason}");
    }

    /**
     * Cria uma exceção para base64 inválido.
     */
    public static function invalidBase64(): self
    {
        return new self('A string base64 fornecida é inválida ou não pôde ser decodificada.');
    }

    /**
     * Cria uma exceção para falha de escrita no disco.
     */
    public static function writeFailed(string $path): self
    {
        return new self("Não foi possível gravar o arquivo no caminho: {$path}");
    }
}
