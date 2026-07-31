# Laravel Uploads

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gsebastiao/laravel-uploads.svg)](https://packagist.org/packages/gsebastiao/laravel-uploads)
[![PHP Version](https://img.shields.io/packagist/php-v/gsebastiao/laravel-uploads.svg)](https://packagist.org/packages/gsebastiao/laravel-uploads)
[![Laravel Version](https://img.shields.io/badge/Laravel-11.x%20%7C%2012.x%20%7C%2013.x-FF2D20.svg)](https://laravel.com)
[![CI](https://github.com/gsebastiao/laravel-uploads/actions/workflows/ci.yml/badge.svg)](https://github.com/gsebastiao/laravel-uploads/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Pacote Laravel para upload de arquivos **multipart** e **base64**, com geração automática de thumbnails para imagens, persistência de metadados e associação polimórfica a qualquer model (morph).

## Recursos

- Upload de arquivos via `UploadedFile` (multipart) ou string base64 (com ou sem prefixo data URI).
- Associação polimórfica (`morphTo`): vincule cada arquivo a qualquer model (User, Post, etc.).
- API flexível: passe o **model direto** (`$user`) ou o **tipo + id** (`User::class, 42`).
- Nomes únicos por UUID e organização em `base_path/{group}/{YYYY}/{MM}/`, onde `{group}` deriva do tipo da entidade.
- Metadados persistidos na tabela `uploads_files` (dimensões, MIME, tamanho, thumbnail, etc.).
- Thumbnails automáticas para imagens (`fit`, `resize` ou `crop`) via Intervention Image v3.
- Validação configurável de extensões/MIME e tamanho máximo.
- Soft deletes e remoção permanente (arquivo + registro).
- Facade `Upload` e binding via container (`UploadInterface`).

## Requisitos

- PHP >= 8.2 (Laravel 13 exige PHP >= 8.3)
- Laravel 11.x, 12.x ou 13.x
- Extensão GD (ou Imagick) para thumbnails

## Instalação

```bash
composer require gsebastiao/laravel-uploads
```

O pacote usa **auto-discovery** do Laravel; o service provider e o alias `Upload` são registrados automaticamente.

### Publicar configuração e migrations

```bash
php artisan vendor:publish --tag=uploads
```

Isso publica `config/uploads.php` e a migration da tabela. Também é possível publicar separadamente:

```bash
php artisan vendor:publish --tag=uploads-config
php artisan vendor:publish --tag=uploads-migrations
```

### Rodar a migration

```bash
php artisan migrate
```

### Disco de armazenamento

O pacote usa o disco `public` por padrão. Garanta que o link simbólico existe:

```bash
php artisan storage:link
```

## Configuração

`config/uploads.php`:

```php
return [
    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],
    'max_size' => 10240, // KB (10 MB)
    'base_path' => 'uploads',
    'thumbnail' => [
        'enabled' => true,
        'width' => 120,
        'height' => 120,
        'quality' => 80,
        'method' => 'fit', // fit, resize, crop
    ],
    'disk' => 'public',
    'url_prefix' => '/storage',
];
```

| Chave | Descrição |
|-------|-----------|
| `allowed_mimes` | Extensões aceitas. A validação também confere o MIME real. |
| `max_size` | Tamanho máximo em KB. |
| `base_path` | Prefixo de diretório dentro do disco. |
| `thumbnail.method` | `fit` mantém proporção; `resize` força as dimensões; `crop` recorta ao centro. |
| `disk` | Disco definido em `config/filesystems.php`. |
| `url_prefix` | Fallback de URL para discos sem `url()`. |
| `auto_detect_uploader` | Se `false`, `uploaded_by` nunca é preenchido automaticamente — só valores explícitos. Omissão: `true`. |
| `auth_guard` | Guard a verificar para detectar o utilizador autenticado. `null` usa o guard por omissão da aplicação. |

## Associação polimórfica (morph)

Cada arquivo é vinculado a uma entidade via as colunas `reference_type` + `reference_id`. Você informa a referência de duas formas equivalentes:

```php
// 1) Passando o model diretamente (tipo e id extraídos automaticamente)
Upload::uploadFile($request->file('avatar'), $user);

// 2) Passando o tipo + id (sem carregar o model)
Upload::uploadFile($request->file('avatar'), \App\Models\User::class, $userId);

// 3) Sem referência (arquivo "shared")
Upload::uploadFile($request->file('avatar'));
```

Para recuperar o dono a partir do arquivo, use a relação `reference`:

```php
$file = Upload::getFile(123);
$owner = $file->reference; // instância de User, Post, etc.
```

## Uso

### Via Facade

```php
use Gsebastiao\LaravelUploads\Facades\Upload;

// Upload multipart vinculado a um model
$file = Upload::uploadFile($request->file('avatar'), $user);

// Upload base64 vinculado por tipo + id
$file = Upload::uploadBase64($base64String, \App\Models\Post::class, $postId);

// Recuperar, listar, remover
$file  = Upload::getFile(123);
$files = Upload::getFilesByReference($post);              // por model
$files = Upload::getFilesByReference(\App\Models\Post::class, $postId); // por tipo + id
Upload::deleteFile(123);          // soft delete
Upload::deleteFile(123, true);    // remoção permanente

// URL pública
$url = Upload::getFileUrl($file);
```

### Via injeção de dependência

```php
use Gsebastiao\LaravelUploads\Contracts\UploadInterface;

public function __construct(private UploadInterface $uploads) {}

public function store(Request $request)
{
    $file = $this->uploads->uploadFile($request->file('doc'), $request->user());
    // ...
}
```

### Exemplo de Controller com validação de Request

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Gsebastiao\LaravelUploads\Facades\Upload;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function updateAvatar(Request $request, User $user)
    {
        $request->validate([
            'avatar' => 'required|file|mimes:jpg,jpeg,png|max:2048',
        ]);

        try {
            $file = Upload::uploadFile($request->file('avatar'), $user);

            return response()->json([
                'success' => true,
                'data'    => $file,
                'url'     => Upload::getFileUrl($file),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
```

### Exemplo base64

```php
$base64 = 'data:image/png;base64,iVBORw0KGgoAAAANS...';

$file = Upload::uploadBase64($base64, $post, filename: 'capa.png');
```

## Categorização e autoria (`category` / `uploaded_by`)

Dois campos opcionais, úteis quando há vários campos de anexo distintos associados ao mesmo registo (ex.: "identidade" e "comprovativo de residência" no mesmo utilizador), ou quando é preciso saber quem fez cada upload:

```php
$file = Upload::uploadFile(
    $request->file('documento'),
    $user,
    category: 'identidade',
    uploadedBy: auth()->id(), // opcional — ver nota abaixo
);

// Filtrar por categoria ao listar
$documentosIdentidade = Upload::getFilesByReference($user, category: 'identidade');
```

- `category` — string livre, sem validação de valores permitidos por omissão. Fica a cargo da aplicação decidir que categorias existem.
- `uploadedBy` — se omitido, o pacote tenta usar automaticamente o utilizador autenticado no momento. Passa um valor explícito para o sobrepor, ou `null` não altera esse comportamento — só um `int` explícito o faz. Este comportamento automático é configurável: `auto_detect_uploader` (desliga por completo) e `auth_guard` (escolhe qual guard verificar) — ver `config/uploads.php`.
- Aceder ao utilizador que fez upload: `$file->uploader` (relação `BelongsTo`, resolve dinamicamente o model configurado em `config('auth.providers.users.model')` — não assume `App\Models\User`).
- Ambos os campos são `nullable`; uploads existentes antes desta funcionalidade continuam válidos, sem necessidade de backfill.

## API do serviço

| Método | Retorno |
|--------|---------|
| `uploadFile(UploadedFile $file, Model\|string\|null $reference = null, ?int $referenceId = null, ?string $category = null, ?int $uploadedBy = null)` | `UploadFile` |
| `uploadBase64(string $base64, Model\|string\|null $reference = null, ?int $referenceId = null, ?string $filename = null, ?string $category = null, ?int $uploadedBy = null)` | `UploadFile` |
| `getFile(int $id)` | `?UploadFile` |
| `deleteFile(int $id, bool $permanent = false)` | `bool` |
| `getFilesByReference(Model\|string $reference, ?int $referenceId = null, ?string $category = null)` | `Collection<int, UploadFile>` |
| `generateThumbnail(string $path)` | `?string` |
| `validateFile(UploadedFile $file)` | `bool` |
| `getFileUrl(UploadFile $file)` | `string` |

Em todos os métodos, `$reference` aceita um model Eloquent (tipo e id extraídos automaticamente), uma string de tipo combinada com `$referenceId`, ou `null` para uploads sem dono.

## Tratamento de erros

- `Gsebastiao\LaravelUploads\Exceptions\ValidationException` — extensão/MIME não permitido ou tamanho excedido.
- `Gsebastiao\LaravelUploads\Exceptions\UploadException` — falha na gravação, base64 inválido, etc.

Todas as operações são registradas via `Log`.

## Estrutura da tabela `uploads_files`

`id`, `reference_type`, `reference_id`, `category`, `uploaded_by`, `filename`, `original_name`, `path`, `full_path`, `size`, `ext`, `mime`, `width`, `height`, `thumbnail`, `status`, `created_at`, `updated_at`, `deleted_at`.

As colunas `reference_type` + `reference_id` formam a relação polimórfica (`nullableMorphs`), com índice composto criado automaticamente. `category` e `uploaded_by` têm índice próprio, e são ambas `nullable`.

## Quickstart: exemplo de integração completa (frontend + backend)

O pacote em si é só o serviço/Facade — não impõe UI nenhuma. Para quem quer
produtividade imediata, o repositório inclui `upload-capture.example.js`
como **referência pronta a copiar**, não como algo que `vendor:publish`
instala automaticamente. Se preferires construir a tua própria interface,
ignora esta secção por completo; o resto do README continua válido sozinho.

`upload-capture.example.js` contém quatro plugins jQuery:

| Plugin | Uso |
|--------|-----|
| `singleCapture` | Um único ficheiro embutido num formulário (câmara + upload), staged até ao submit. |
| `multipleCapture` | Vários ficheiros, mesma lógica staged, grid com pré-visualização e download. |
| `uploadCapture` | Escolhe automaticamente entre os dois acima consoante a opção `multiple`. |
| `uploadFile` | Gestor de anexos em modal próprio, agindo **imediatamente** contra o servidor (upload e remoção não esperam por nenhum submit) — lista, zip de "descarregar tudo", suporte a categorias. |

Cada plugin tem a sua própria documentação completa via JSDoc dentro do
ficheiro — as opções todas, com exemplos, estão lá, não repetidas aqui.

O exemplo abaixo usa `uploadFile` (o cenário "anexos de um registo já
existente"), com um model `Post` genérico — adapta o nome do model, das
rotas e da tabela conforme a tua aplicação.

### Controller de exemplo

```php
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Gsebastiao\LaravelUploads\Facades\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostAttachmentController extends Controller
{
    public function index(Request $request, Post $post): JsonResponse
    {
        $files = Upload::getFilesByReference($post, category: $request->input('category'));

        return response()->json([
            'success' => true,
            'files' => $files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'url' => Upload::getFileUrl($file),
                'mime' => $file->mime,
                'size' => $file->size,
                'uploaded_at' => optional($file->created_at)->toDateTimeString(),
                'uploaded_by' => $file->uploaded_by,
            ]),
        ]);
    }

    public function store(Request $request, Post $post): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        try {
            $file = Upload::uploadFile($request->file('file'), $post, category: $request->input('category'));

            return response()->json([
                'success' => true,
                'file' => ['id' => $file->id, 'name' => $file->original_name, 'url' => Upload::getFileUrl($file)],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        foreach ($request->input('ids') as $id) {
            Upload::deleteFile($id, permanent: true);
        }

        return response()->json(['success' => true]);
    }

    public function downloadAll(Post $post): mixed
    {
        $files = Upload::getFilesByReference($post);

        if ($files->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Sem ficheiros.'], 404);
        }

        $disk = config('uploads.disk', 'public');
        $zipPath = storage_path('app/tmp/anexos_' . $post->id . '_' . now()->format('YmdHis') . '.zip');

        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $file) {
            $absolutePath = Storage::disk($disk)->path($file->path);
            if (is_file($absolutePath)) {
                $zip->addFile($absolutePath, $file->original_name);
            }
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}
```

### Rotas de exemplo

```php
use App\Http\Controllers\PostAttachmentController;

Route::get('/posts/{post}/attachments', [PostAttachmentController::class, 'index'])->name('posts.attachments.index');
Route::post('/posts/{post}/attachments', [PostAttachmentController::class, 'store'])->name('posts.attachments.store');
Route::post('/posts/attachments/remove', [PostAttachmentController::class, 'destroy'])->name('posts.attachments.destroy');
Route::get('/posts/{post}/attachments/download-all', [PostAttachmentController::class, 'downloadAll'])->name('posts.attachments.downloadAll');
```

### Vista de exemplo

```blade
<input type="hidden" name="attachments[]">

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="{{ asset('js/upload-capture.js') }}"></script> {{-- a tua cópia de upload-capture.example.js --}}

<script>
    $('input[name="attachments[]"]').uploadFile({
        multiple: true,
        listUrl: '{{ route('posts.attachments.index', $post) }}',
        uploadUrl: '{{ route('posts.attachments.store', $post) }}',
        deleteUrl: '{{ route('posts.attachments.destroy') }}',
        downloadAllUrl: '{{ route('posts.attachments.downloadAll', $post) }}',
        title: 'Anexos'
    });
</script>
```

Para `singleCapture`/`multipleCapture` (ficheiros staged, submetidos junto
com o resto de um formulário — não imediatos como `uploadFile`), consulta o
JSDoc de cada um em `upload-capture.example.js`; o princípio de integração
com o backend é o mesmo (`Upload::getFilesByReference`/`uploadFile`/
`uploadBase64`/`deleteFile`), só a forma como o JS envia os dados muda.

## Testes

```bash
composer install
vendor/bin/phpunit
```

## Análise estática (opcional)

```bash
vendor/bin/phpstan analyse
```

## Licença

MIT. Veja [LICENSE](LICENSE).
