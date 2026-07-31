<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Contracts;

use Gsebastiao\LaravelUploads\Models\UploadFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Contrato público do serviço de uploads.
 *
 * O parâmetro $reference aceita duas formas:
 *  - Um Model Eloquent: o tipo e o id são extraídos automaticamente.
 *  - Uma string (nome da classe/tipo) combinada com $referenceId.
 *  - null: upload sem entidade associada.
 */
interface UploadInterface
{
    /**
     * Realiza o upload de um arquivo multipart.
     *
     * @param UploadedFile      $file        Arquivo recebido do request.
     * @param Model|string|null $reference   Model dono, ou tipo (string), ou null.
     * @param int|null          $referenceId ID quando $reference é string.
     * @param string|null       $category    Categoria/agrupamento opcional (ex.: vários campos de anexo no mesmo registo).
     * @param int|null          $uploadedBy  ID do utilizador que fez o upload. Se omitido, tenta o utilizador autenticado no momento.
     */
    public function uploadFile(UploadedFile $file, Model|string|null $reference = null, ?int $referenceId = null, ?string $category = null, ?int $uploadedBy = null): UploadFile;

    /**
     * Realiza o upload a partir de uma string base64.
     *
     * @param string            $base64      Conteúdo base64, com ou sem data URI.
     * @param Model|string|null $reference   Model dono, ou tipo (string), ou null.
     * @param int|null          $referenceId ID quando $reference é string.
     * @param string|null       $filename    Nome original opcional.
     * @param string|null       $category    Categoria/agrupamento opcional.
     * @param int|null          $uploadedBy  ID do utilizador que fez o upload. Se omitido, tenta o utilizador autenticado no momento.
     */
    public function uploadBase64(string $base64, Model|string|null $reference = null, ?int $referenceId = null, ?string $filename = null, ?string $category = null, ?int $uploadedBy = null): UploadFile;

    /**
     * Recupera um registro de arquivo por ID.
     */
    public function getFile(int $id): ?UploadFile;

    /**
     * Remove um arquivo (soft delete por padrão; permanente se solicitado).
     */
    public function deleteFile(int $id, bool $permanent = false): bool;

    /**
     * Lista arquivos de uma entidade de referência.
     *
     * @param Model|string      $reference   Model dono ou tipo (string).
     * @param int|null          $referenceId ID quando $reference é string.
     * @param string|null       $category    Filtra por categoria, quando definida.
     * @return Collection<int, UploadFile>
     */
    public function getFilesByReference(Model|string $reference, ?int $referenceId = null, ?string $category = null): Collection;

    /**
     * Gera uma thumbnail a partir de um caminho relativo no disco.
     *
     * @return string|null Caminho relativo da thumbnail, ou null se não aplicável.
     */
    public function generateThumbnail(string $path): ?string;

    /**
     * Valida um arquivo contra as regras configuradas.
     */
    public function validateFile(UploadedFile $file): bool;

    /**
     * Resolve a URL pública de um arquivo.
     */
    public function getFileUrl(UploadFile $file): string;
}
