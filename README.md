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

## API do serviço

| Método | Retorno |
|--------|---------|
| `uploadFile(UploadedFile $file, Model\|string\|null $reference = null, ?int $referenceId = null)` | `UploadFile` |
| `uploadBase64(string $base64, Model\|string\|null $reference = null, ?int $referenceId = null, ?string $filename = null)` | `UploadFile` |
| `getFile(int $id)` | `?UploadFile` |
| `deleteFile(int $id, bool $permanent = false)` | `bool` |
| `getFilesByReference(Model\|string $reference, ?int $referenceId = null)` | `Collection<int, UploadFile>` |
| `generateThumbnail(string $path)` | `?string` |
| `validateFile(UploadedFile $file)` | `bool` |
| `getFileUrl(UploadFile $file)` | `string` |

Em todos os métodos, `$reference` aceita um model Eloquent (tipo e id extraídos automaticamente), uma string de tipo combinada com `$referenceId`, ou `null` para uploads sem dono.

## Tratamento de erros

- `Gsebastiao\LaravelUploads\Exceptions\ValidationException` — extensão/MIME não permitido ou tamanho excedido.
- `Gsebastiao\LaravelUploads\Exceptions\UploadException` — falha na gravação, base64 inválido, etc.

Todas as operações são registradas via `Log`.

## Estrutura da tabela `uploads_files`

`id`, `reference_type`, `reference_id`, `filename`, `original_name`, `path`, `full_path`, `size`, `ext`, `mime`, `width`, `height`, `thumbnail`, `status`, `created_at`, `updated_at`, `deleted_at`.

As colunas `reference_type` + `reference_id` formam a relação polimórfica (`nullableMorphs`), com índice composto criado automaticamente.

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
