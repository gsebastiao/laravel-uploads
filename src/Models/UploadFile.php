<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Representa um arquivo enviado e seus metadados.
 *
 * @property int         $id
 * @property string|null $reference_type
 * @property int|null    $reference_id
 * @property string|null $category
 * @property int|null    $uploaded_by
 * @property string      $filename
 * @property string      $original_name
 * @property string      $path
 * @property string      $full_path
 * @property int         $size
 * @property string      $ext
 * @property string      $mime
 * @property int|null    $width
 * @property int|null    $height
 * @property string|null $thumbnail
 * @property string      $status
 */
class UploadFile extends Model
{
    use SoftDeletes;

    /**
     * Tabela associada ao model.
     */
    protected $table = 'uploads_files';

    /**
     * Atributos atribuíveis em massa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference_type',
        'reference_id',
        'category',
        'uploaded_by',
        'filename',
        'original_name',
        'path',
        'full_path',
        'size',
        'ext',
        'mime',
        'width',
        'height',
        'thumbnail',
        'status',
    ];

    /**
     * Casts de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_id' => 'integer',
            'uploaded_by' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * Entidade polimórfica dona do arquivo (ex.: User, Post).
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Utilizador que fez o upload (uploaded_by), se aplicável.
     *
     * Resolve o model de User dinamicamente a partir de
     * config('auth.providers.users.model') em vez de assumir App\Models\User
     * — o pacote não faz essa suposição em mais lado nenhum, e a aplicação
     * anfitriã pode ter um model de utilizador diferente.
     *
     * @return BelongsTo<Model, $this>
     */
    public function uploader(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'uploaded_by');
    }

    /**
     * Indica se o arquivo é uma imagem.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Indica se o arquivo possui thumbnail gerada.
     */
    public function hasThumbnail(): bool
    {
        return $this->thumbnail !== null && $this->thumbnail !== '';
    }
}
