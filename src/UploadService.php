<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads;

use Gsebastiao\LaravelUploads\Contracts\UploadInterface;
use Gsebastiao\LaravelUploads\Exceptions\UploadException;
use Gsebastiao\LaravelUploads\Exceptions\ValidationException;
use Gsebastiao\LaravelUploads\Models\UploadFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Serviço responsável por armazenar uploads (multipart e base64),
 * persistir metadados e gerar thumbnails para imagens.
 */
class UploadService implements UploadInterface
{
    /**
     * @param array<string, mixed> $config Configuração resolvida de config/uploads.php.
     */
    public function __construct(
        protected array $config,
        protected ?ImageManager $imageManager = null,
    ) {
        $this->imageManager ??= ImageManager::gd();
    }

    /**
     * {@inheritDoc}
     */
    public function uploadFile(UploadedFile $file, Model|string|null $reference = null, ?int $referenceId = null): UploadFile
    {
        $this->validateFile($file);

        [$refType, $refId] = $this->resolveReference($reference, $referenceId);

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $filename = Str::uuid()->toString() . '.' . $ext;
        $relativePath = $this->buildPath($refType, $filename);

        try {
            $stored = $this->disk()->putFileAs(
                dirname($relativePath),
                $file,
                basename($relativePath)
            );

            if ($stored === false) {
                throw UploadException::writeFailed($relativePath);
            }
        } catch (Throwable $e) {
            Log::error('[laravel-uploads] Falha ao gravar arquivo multipart.', [
                'reference_type' => $refType,
                'error' => $e->getMessage(),
            ]);

            throw $e instanceof UploadException ? $e : UploadException::forReason($e->getMessage());
        }

        [$width, $height] = $this->dimensionsFor($relativePath, $file->getMimeType());
        $thumbnail = $this->generateThumbnail($relativePath);

        $model = $this->persist([
            'reference_type' => $refType,
            'reference_id' => $refId,
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'path' => $relativePath,
            'full_path' => $this->disk()->path($relativePath),
            'size' => (int) $file->getSize(),
            'ext' => $ext,
            'mime' => (string) $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'thumbnail' => $thumbnail,
        ]);

        Log::info('[laravel-uploads] Upload multipart concluído.', ['id' => $model->id, 'reference_type' => $refType]);

        return $model;
    }

    /**
     * {@inheritDoc}
     */
    public function uploadBase64(string $base64, Model|string|null $reference = null, ?int $referenceId = null, ?string $filename = null): UploadFile
    {
        [$mime, $binary] = $this->decodeBase64($base64);
        $ext = $this->extensionFromMime($mime);

        if (! in_array($ext, $this->allowedMimes(), true)) {
            throw ValidationException::invalidMime($ext, $this->allowedMimes());
        }

        $sizeKb = (int) ceil(strlen($binary) / 1024);
        if ($sizeKb > $this->maxSize()) {
            throw ValidationException::tooLarge($sizeKb, $this->maxSize());
        }

        [$refType, $refId] = $this->resolveReference($reference, $referenceId);

        $generatedName = Str::uuid()->toString() . '.' . $ext;
        $relativePath = $this->buildPath($refType, $generatedName);

        try {
            if ($this->disk()->put($relativePath, $binary) === false) {
                throw UploadException::writeFailed($relativePath);
            }
        } catch (Throwable $e) {
            Log::error('[laravel-uploads] Falha ao gravar arquivo base64.', [
                'reference_type' => $refType,
                'error' => $e->getMessage(),
            ]);

            throw $e instanceof UploadException ? $e : UploadException::forReason($e->getMessage());
        }

        [$width, $height] = $this->dimensionsFor($relativePath, $mime);
        $thumbnail = $this->generateThumbnail($relativePath);

        $model = $this->persist([
            'reference_type' => $refType,
            'reference_id' => $refId,
            'filename' => $generatedName,
            'original_name' => $filename ?? $generatedName,
            'path' => $relativePath,
            'full_path' => $this->disk()->path($relativePath),
            'size' => strlen($binary),
            'ext' => $ext,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'thumbnail' => $thumbnail,
        ]);

        Log::info('[laravel-uploads] Upload base64 concluído.', ['id' => $model->id, 'reference_type' => $refType]);

        return $model;
    }

    /**
     * {@inheritDoc}
     */
    public function getFile(int $id): ?UploadFile
    {
        return UploadFile::find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFile(int $id, bool $permanent = false): bool
    {
        $file = UploadFile::withTrashed()->find($id);

        if ($file === null) {
            return false;
        }

        if ($permanent) {
            $this->disk()->delete($file->path);

            if ($file->hasThumbnail()) {
                $this->disk()->delete($file->thumbnail);
            }

            $result = (bool) $file->forceDelete();
            Log::info('[laravel-uploads] Arquivo removido permanentemente.', ['id' => $id]);

            return $result;
        }

        $result = (bool) $file->delete();
        Log::info('[laravel-uploads] Arquivo movido para lixeira (soft delete).', ['id' => $id]);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getFilesByReference(Model|string $reference, ?int $referenceId = null): Collection
    {
        [$refType, $refId] = $this->resolveReference($reference, $referenceId);

        return UploadFile::query()
            ->where('reference_type', $refType)
            ->when($refId !== null, fn ($query) => $query->where('reference_id', $refId))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function generateThumbnail(string $path): ?string
    {
        $thumbConfig = $this->config['thumbnail'] ?? [];

        if (! ($thumbConfig['enabled'] ?? false)) {
            return null;
        }

        if (! $this->isImagePath($path)) {
            return null;
        }

        try {
            $contents = $this->disk()->get($path);

            if ($contents === null) {
                return null;
            }

            $image = $this->imageManager->read($contents);
            $image = $this->applyThumbnailMethod(
                $image,
                (int) ($thumbConfig['width'] ?? 120),
                (int) ($thumbConfig['height'] ?? 120),
                (string) ($thumbConfig['method'] ?? 'fit'),
            );

            $quality = (int) ($thumbConfig['quality'] ?? 80);
            $encoded = (string) $image->encodeByExtension(
                pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg',
                quality: $quality
            );

            $thumbPath = $this->thumbnailPathFor($path);
            $this->disk()->put($thumbPath, $encoded);

            return $thumbPath;
        } catch (Throwable $e) {
            Log::warning('[laravel-uploads] Não foi possível gerar thumbnail.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function validateFile(UploadedFile $file): bool
    {
        if (! $file->isValid()) {
            throw ValidationException::invalidFile();
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        if (! in_array($ext, $this->allowedMimes(), true)) {
            throw ValidationException::invalidMime($ext, $this->allowedMimes());
        }

        // getSize() retorna bytes; converte para KB.
        $sizeKb = (int) ceil(((int) $file->getSize()) / 1024);

        if ($sizeKb > $this->maxSize()) {
            throw ValidationException::tooLarge($sizeKb, $this->maxSize());
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getFileUrl(UploadFile $file): string
    {
        try {
            return $this->disk()->url($file->path);
        } catch (Throwable) {
            // Fallback para discos que não expõem url().
            $prefix = rtrim((string) ($this->config['url_prefix'] ?? '/storage'), '/');

            return $prefix . '/' . ltrim($file->path, '/');
        }
    }

    /**
     * Normaliza a referência em [tipo, id].
     *
     * Aceita um Model (extrai getMorphClass() e getKey()), uma string de tipo
     * combinada com $referenceId, ou null (sem referência).
     *
     * @return array{0: string|null, 1: int|null}
     */
    protected function resolveReference(Model|string|null $reference, ?int $referenceId): array
    {
        if ($reference instanceof Model) {
            /** @var int|string|null $key */
            $key = $reference->getKey();

            return [$reference->getMorphClass(), $key === null ? null : (int) $key];
        }

        if (is_string($reference)) {
            return [$reference, $referenceId];
        }

        return [null, null];
    }

    /**
     * Persiste os metadados aplicando o status padrão.
     *
     * @param array<string, mixed> $attributes
     */
    protected function persist(array $attributes): UploadFile
    {
        $attributes['status'] ??= 'active';

        return UploadFile::create($attributes);
    }

    /**
     * Aplica o método de redimensionamento configurado.
     */
    protected function applyThumbnailMethod(ImageInterface $image, int $width, int $height, string $method): ImageInterface
    {
        return match ($method) {
            'resize' => $image->resize($width, $height),
            'crop' => $image->coverDown($width, $height),
            default => $image->scaleDown($width, $height), // 'fit' mantém proporção.
        };
    }

    /**
     * Decodifica uma string base64, com ou sem prefixo data URI.
     *
     * @return array{0: string, 1: string} [mime, binário]
     */
    protected function decodeBase64(string $base64): array
    {
        $mime = 'application/octet-stream';
        $data = $base64;

        if (preg_match('/^data:(?<mime>[\w\/\-\.\+]+);base64,(?<data>.+)$/s', $base64, $matches) === 1) {
            $mime = $matches['mime'];
            $data = $matches['data'];
        }

        $binary = base64_decode(str_replace(' ', '+', $data), true);

        if ($binary === false || $binary === '') {
            throw UploadException::invalidBase64();
        }

        // Se não havia prefixo, tenta detectar o MIME pelo conteúdo.
        if ($mime === 'application/octet-stream') {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }

        return [$mime, $binary];
    }

    /**
     * Monta o caminho relativo: base_path/group/YYYY/MM/filename.
     *
     * O "group" deriva do tipo da referência (ex.: "App\Models\User" -> "user"),
     * ou "shared" quando não há referência.
     */
    protected function buildPath(?string $referenceType, string $filename): string
    {
        $base = trim((string) ($this->config['base_path'] ?? 'uploads'), '/');
        $group = $this->groupFromType($referenceType);

        return sprintf('%s/%s/%s/%s/%s', $base, $group, date('Y'), date('m'), $filename);
    }

    /**
     * Deriva um segmento de diretório amigável a partir do tipo da referência.
     */
    protected function groupFromType(?string $referenceType): string
    {
        if ($referenceType === null || $referenceType === '') {
            return 'shared';
        }

        // Usa apenas o nome curto da classe, em snake_case (User -> user).
        $short = class_basename($referenceType);

        return Str::snake($short) ?: 'shared';
    }

    /**
     * Deriva o caminho da thumbnail a partir do caminho original.
     */
    protected function thumbnailPathFor(string $path): string
    {
        $dir = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return sprintf('%s/thumbs/%s_thumb.%s', $dir, $name, $ext ?: 'jpg');
    }

    /**
     * Obtém largura/altura de uma imagem armazenada, se aplicável.
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function dimensionsFor(string $path, ?string $mime): array
    {
        if ($mime === null || ! str_starts_with($mime, 'image/')) {
            return [null, null];
        }

        try {
            $contents = $this->disk()->get($path);
            if ($contents === null) {
                return [null, null];
            }

            $image = $this->imageManager->read($contents);

            return [$image->width(), $image->height()];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * Verifica se o caminho aponta para uma extensão de imagem.
     */
    protected function isImagePath(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }

    /**
     * Mapeia um MIME type para uma extensão de arquivo.
     */
    protected function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            default => (function () use ($mime): string {
                $parts = explode('/', $mime);

                return strtolower($parts[1] ?? 'bin');
            })(),
        };
    }

    /**
     * @return list<string>
     */
    protected function allowedMimes(): array
    {
        /** @var list<string> $mimes */
        $mimes = $this->config['allowed_mimes'] ?? [];

        return array_map('strtolower', $mimes);
    }

    protected function maxSize(): int
    {
        return (int) ($this->config['max_size'] ?? 10240);
    }

    /**
     * Resolve o disco de armazenamento configurado.
     */
    protected function disk(): Filesystem
    {
        return Storage::disk((string) ($this->config['disk'] ?? 'public'));
    }
}
