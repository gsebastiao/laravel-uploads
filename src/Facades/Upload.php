<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Facades;

use Gsebastiao\LaravelUploads\Contracts\UploadInterface;
use Gsebastiao\LaravelUploads\Models\UploadFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static UploadFile uploadFile(UploadedFile $file, Model|string|null $reference = null, ?int $referenceId = null)
 * @method static UploadFile uploadBase64(string $base64, Model|string|null $reference = null, ?int $referenceId = null, ?string $filename = null)
 * @method static UploadFile|null getFile(int $id)
 * @method static bool deleteFile(int $id, bool $permanent = false)
 * @method static Collection<int, UploadFile> getFilesByReference(Model|string $reference, ?int $referenceId = null)
 * @method static string|null generateThumbnail(string $path)
 * @method static bool validateFile(UploadedFile $file)
 * @method static string getFileUrl(UploadFile $file)
 *
 * @see \Gsebastiao\LaravelUploads\UploadService
 */
class Upload extends Facade
{
    /**
     * Retorna a chave de binding registrada no container.
     */
    protected static function getFacadeAccessor(): string
    {
        return UploadInterface::class;
    }
}
