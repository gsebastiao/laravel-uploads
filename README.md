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
| `assets_path` | Destino (dentro de `public/`) do plugin JS ao publicar via `vendor:publish --tag=uploads-assets`. |

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

## Usar o plugin JS (opcional)

Tudo o que vem a seguir é **opcional** — o pacote em si é só o
serviço/Facade `Upload`, que já viste acima. Esta secção é só para quem
não quer construir a interface (câmara, upload, pré-visualização, grelha
de anexos) do zero.

### Como obter o ficheiro

```bash
php artisan vendor:publish --tag=uploads-assets
```

Copia o plugin para `public/assets/js/upload-capture.js` (o destino é
configurável, via `config('uploads.assets_path')`). Depois, na tua vista:

```blade
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="{{ asset('assets/js/upload-capture.js') }}"></script>
```

Precisa de jQuery — não tem outras dependências.

### Qual dos dois usar?

O ficheiro tem dois "modos" bem diferentes. Se não tiveres a certeza de
qual precisas, esta tabela resolve:

| A tua situação | Usa |
|---|---|
| Tens um formulário (criar/editar um registo) e queres deixar a pessoa escolher fotos/documentos **antes** de carregar em "Guardar" — tudo submete junto, de uma vez | **`uploadCapture`** |
| Queres um botão "Anexos" que abre uma janela a mostrar ficheiros **já guardados**, onde adicionar/remover acontece **na hora**, sem precisar de nenhum botão "Guardar" à parte | **`uploadFile`** |

Em resumo: `uploadCapture` fica **dentro** de um formulário e espera pelo
submit. `uploadFile` **não** espera por nada — age assim que clicas.

---

## `uploadCapture` — ficheiros dentro de um formulário

### Quando usar

Sempre que tiveres um formulário normal (`<form>`) e quiseres que a pessoa
escolha ficheiros como parte de o preencher — os ficheiros só chegam ao
servidor quando o formulário inteiro for submetido, junto com todos os
outros campos.

### HTML mínimo

```html
<form method="POST" action="/posts">
    @csrf

    <input type="text" name="titulo">

    {{-- Este input vai ser escondido pelo plugin e substituído pelo
         widget visual — mas continua a fazer parte do formulário. --}}
    <input type="file" name="anexos[]">

    <button type="submit">Guardar</button>
</form>
```

### Inicializar

```javascript
$('input[name="anexos[]"]').uploadCapture({
    multiple: true,
    maxFiles: 10,
    gridCols: 4,
    height: 250
});
```

Para um único ficheiro (ex.: foto de perfil), `multiple: false` — nesse
caso o widget mostra uma única caixa em vez de uma grelha.

### Todas as opções

| Opção | Tipo | Omissão | O que faz |
|---|---|---|---|
| `multiple` | `boolean` | `true` | `true` = vários ficheiros (grelha). `false` = um só. |
| `maxFiles` | `number` | `10` | Máximo de ficheiros, só relevante com `multiple: true`. |
| `gridCols` | `number` | `3` | Colunas da grelha, só relevante com `multiple: true`. |
| `height` | `number` | `200` | Altura do widget, em pixels. |
| `width` | `string` | `'100%'` | Largura do widget (aceita qualquer valor CSS, ex.: `'300px'`). |
| `accept` | `string` | *(nenhum)* | Restringe tipos de ficheiro no selector (ex.: `'image/*'`). **Sem isto, aceita qualquer tipo** — a validação a sério é sempre feita no servidor, nunca confies só nisto. |
| `quality` | `number` | `0.8` | Qualidade da compressão ao capturar por câmara (0 a 1). |
| `defaultImage` | `string` | `null` | URL de uma imagem já existente, para pré-popular (só com `multiple: false`). |
| `defaultImages` | `Array` | `[]` | Lista de URLs já existentes, para pré-popular (só com `multiple: true`). |
| `title` (dentro de `messages`) | `Object` | — | Textos personalizados — ver JSDoc no ficheiro para a forma exacta. |
| `onChange` | `Function` | `null` | Chamada sempre que a lista de ficheiros muda. |
| `onRemove` | `Function` | `null` | Chamada quando um ficheiro é removido. |
| `onReady` | `Function` | `null` | Chamada quando o widget termina de ser montado. |

### O que chega ao servidor quando o formulário é submetido

Isto é a parte que costuma confundir mais no início, por isso vale a pena
explicar devagar. O `<input type="file" name="anexos[]">` original é
escondido pelo plugin — os dados reais viajam por **outros campos**,
criados automaticamente:

| Campo enviado | Quando aparece | O que contém |
|---|---|---|
| `anexos_base64[]` | Ficheiros escolhidos/tirados **nesta sessão** (upload, câmara, arrastar) | O conteúdo do ficheiro, como texto `data:<mime>;base64,...` |
| `anexos_base64_names[]` | O mesmo caso acima | O nome original do ficheiro, na mesma posição que `anexos_base64[]` (índice 0 corresponde a índice 0, e por aí fora). Capturas de câmara não têm nome original, por isso vêm vazias. |
| `anexos_paths[]` | Ficheiros que já existiam antes (ex.: reabriste um registo para editar, via `setImages()`) **e continuam lá** — não foram removidos no ecrã | O URL de cada ficheiro já existente |

Se estiveres a **criar** um registo novo, só vais ver `anexos_base64[]` +
`anexos_base64_names[]` (não havia nada "já existente" para aparecer em
`anexos_paths[]`). Se estiveres a **editar** um registo que já tinha
ficheiros, e a pessoa não mexeu em nada, vês só `anexos_paths[]`. Se a
pessoa removeu um ficheiro já existente no ecrã, ele simplesmente **não
aparece** em `anexos_paths[]` — é assim que sabes, do lado do servidor,
que foi removido (mais sobre isto no exemplo de controller a seguir).

### Exemplo de controller completo

```php
<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Gsebastiao\LaravelUploads\Facades\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate(['titulo' => ['required', 'string']]);

        // Ficheiros novos, em base64 (upload/câmara desta sessão).
        $base64Items = collect($request->input('anexos_base64', []))
            ->filter(fn ($item) => is_string($item) && $item !== '')
            ->values();

        $base64Names = collect($request->input('anexos_base64_names', []))->values();

        $post = DB::transaction(function () use ($request, $base64Items, $base64Names) {
            $post = Post::create(['titulo' => $request->input('titulo')]);

            foreach ($base64Items as $index => $item) {
                Upload::uploadBase64(
                    $item,
                    $post,
                    filename: $base64Names->get($index), // pode vir vazio (capturas de câmara) — uploadBase64 gera um nome nesse caso
                );
            }

            return $post;
        });

        return response()->json(['success' => true, 'id' => $post->id]);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        $request->validate(['titulo' => ['required', 'string']]);

        $base64Items = collect($request->input('anexos_base64', []))
            ->filter(fn ($item) => is_string($item) && $item !== '')
            ->values();
        $base64Names = collect($request->input('anexos_base64_names', []))->values();

        // Ficheiros já existentes que SOBREVIVERAM no ecrã (não foram removidos).
        $keptPaths = collect($request->input('anexos_paths', []))
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->values();

        DB::transaction(function () use ($request, $post, $base64Items, $base64Names, $keptPaths) {
            $post->update(['titulo' => $request->input('titulo')]);

            // Ficheiros novos.
            foreach ($base64Items as $index => $item) {
                Upload::uploadBase64($item, $post, filename: $base64Names->get($index));
            }

            // O que já existia na BD, mas já NÃO está em $keptPaths, foi
            // removido no ecrã — é isso que apagamos aqui.
            $existing = Upload::getFilesByReference($post);
            foreach ($existing as $file) {
                if (! $keptPaths->contains(Upload::getFileUrl($file))) {
                    Upload::deleteFile($file->id, permanent: true);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    // Para popular o widget ao reabrir um registo para editar — ver a
    // seguir "Repor ficheiros já existentes ao editar".
    public function edit(Post $post): JsonResponse
    {
        $files = Upload::getFilesByReference($post);

        return response()->json([
            'success' => true,
            'titulo' => $post->titulo,
            'anexos' => $files->map(fn ($file) => [
                'src' => Upload::getFileUrl($file),
                'name' => $file->original_name,
                'mime' => $file->mime,
            ]),
        ]);
    }
}
```

### Repor ficheiros já existentes ao editar

Quando reabres um registo para editar, precisas de mostrar os ficheiros
que já lá estão. É para isto que serve `setImages()`:

```javascript
$.get('/posts/42/edit', function (response) {
    $('input[name="titulo"]').val(response.titulo);

    // response.anexos já vem no formato {src, name, mime} — o nome real
    // do ficheiro aparece na grelha, não um genérico "Documento".
    $('input[name="anexos[]"]').uploadCapture('setImages', response.anexos);
});
```

`setImages()` também aceita só uma lista de URLs simples
(`['/uploads/a.png', '/uploads/b.pdf']`), se não precisares dos nomes —
mas com objectos `{src, name, mime}` fica mais completo.

### Métodos públicos

Chamam-se assim: `$('input[name="anexos[]"]').uploadCapture('nomeDoMetodo', argumento)`.

| Método | Argumento | O que faz |
|---|---|---|
| `setImages` | `Array` de strings ou de `{src, name, mime}` | Substitui a lista actual pelos itens dados. |
| `addImage` | string ou `{src, name, mime}` | Adiciona um item à lista actual. |
| `clear` | — | Remove todos os itens. |
| `reset` | — | Volta ao estado inicial (equivalente a `clear`, mais limpeza interna). |
| `destroy` | — | Remove o widget por completo, devolve o `<input>` original. |

---

## `uploadFile` — gerir anexos já existentes

### Quando usar

Quando precisas de um botão/link "Anexos" que abre uma janela mostrando
ficheiros **já guardados no servidor**, e adicionar ou remover deve
acontecer **imediatamente** — sem esperar por nenhum botão "Guardar". É a
diferença chave em relação ao `uploadCapture`: aqui, cada acção já fala
directamente com o servidor.

### HTML mínimo

```html
<input type="hidden" id="anexosInput">
```

Não precisa de estar dentro de nenhum `<form>` — este plugin não depende
de submit nenhum.

### Inicializar

```javascript
$('#anexosInput').uploadFile({
    multiple: true,
    listUrl: '/posts/42/attachments',
    uploadUrl: '/posts/42/attachments',
    deleteUrl: '/posts/attachments/remove',
    title: 'Anexos'
});
```

### Todas as opções

| Opção | Tipo | Obrigatória? | O que faz |
|---|---|---|---|
| `multiple` | `boolean` | Não (omissão `true`) | `true` = vários anexos. `false` = só 1. |
| `maxFiles` | `number` | Não | Máximo de anexos, só com `multiple: true`. Sem limite se omitido. |
| `referenceId` | `number` \| `string` | Não | Valor **inicial** — ver "Várias linhas de uma tabela" abaixo para o caso mais comum de não precisares disto aqui. |
| `category` | `string` | Não | Ver "Vários campos de anexo no mesmo registo" abaixo. |
| `listUrl` | `string` | **Sim** | URL que devolve a lista de anexos (GET). |
| `uploadUrl` | `string` | **Sim** | URL que recebe um novo ficheiro (POST). |
| `deleteUrl` | `string` | **Sim** | URL que remove anexos (POST). |
| `downloadAllUrl` | `string` | Não | URL que devolve um `.zip` de tudo. Sem isto, "Download All" descarrega ficheiro a ficheiro. |
| `accept` | `string` | Não | Restringe tipos no selector. Sem isto, aceita qualquer tipo. |
| `title` | `string` | Não (omissão `'Anexos'`) | Título mostrado no topo do modal. |
| `confirmRemove` | `boolean` | Não (omissão `true`) | Pede confirmação antes de remover. |
| `onChange` | `Function` | Não | Chamada sempre que a lista muda (upload ou remoção concluídos). |
| `onReady` | `Function` | Não | Chamada quando o plugin termina de ser montado. |

### Os quatro endpoints, um por um

Isto é o que cada URL precisa de **receber** e de **devolver**. Todos os
exemplos de resposta assumem `{success: true, ...}` em caso de sucesso —
em caso de erro, `{success: false, message: 'texto do erro'}`.

#### `listUrl` (GET)

**Recebe**: `?id=42` (e `&category=...`, se tiveres configurado categoria).

**Deve devolver**:
```json
{
    "success": true,
    "files": [
        {
            "id": 7,
            "name": "contrato.pdf",
            "url": "https://.../storage/uploads/.../abc.pdf",
            "mime": "application/pdf",
            "size": 102400,
            "uploaded_at": "2026-07-31 10:32:00",
            "uploaded_by": 3
        }
    ]
}
```

```php
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
```

#### `uploadUrl` (POST, multipart)

**Recebe**: campo `file` (o ficheiro), `id` (o registo), e `category` se
aplicável — o plugin trata de montar isto sozinho, não precisas de fazer
nada no frontend além de configurar o URL.

**Deve devolver**:
```json
{ "success": true, "file": { "id": 8, "name": "novo.png", "url": "..." } }
```

```php
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
```

#### `deleteUrl` (POST)

**Recebe**: `ids` (array de ids a remover, mesmo que seja só um), `id` (o registo).

**Deve devolver**: `{ "success": true }`

```php
public function destroy(Request $request): JsonResponse
{
    $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

    foreach ($request->input('ids') as $id) {
        Upload::deleteFile($id, permanent: true);
    }

    return response()->json(['success' => true]);
}
```

#### `downloadAllUrl` (GET, opcional)

**Recebe**: `?id=42`.

**Deve devolver**: o ficheiro `.zip` directamente (não JSON) — usa
`response()->download(...)`.

```php
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
        $absolutePath = \Illuminate\Support\Facades\Storage::disk($disk)->path($file->path);
        if (is_file($absolutePath)) {
            $zip->addFile($absolutePath, $file->original_name);
        }
    }
    $zip->close();

    return response()->download($zipPath)->deleteFileAfterSend(true);
}
```

### Rotas de exemplo

```php
Route::get('/posts/{post}/attachments', [PostController::class, 'index']);
Route::post('/posts/{post}/attachments', [PostController::class, 'store']);
Route::post('/posts/attachments/remove', [PostController::class, 'destroy']);
Route::get('/posts/{post}/attachments/download-all', [PostController::class, 'downloadAll']);
```

### Várias linhas de uma tabela (o caso mais comum)

Se tiveres uma tabela com várias linhas, cada uma com o seu botão
"Anexos", **não inicializes o plugin uma vez por linha**. Inicializa uma
vez, sem `referenceId` nenhum, e passa o id de cada linha ao **abrir**:

```javascript
// Uma vez, ao carregar a página:
$('#anexosInput').uploadFile({
    multiple: true,
    listUrl: '/posts/attachments',
    uploadUrl: '/posts/attachments',
    deleteUrl: '/posts/attachments/remove'
});

// A cada clique num botão de uma linha:
$(document).on('click', '.btnAnexos', function () {
    $('#anexosInput').uploadFile('open', $(this).data('id'));
});
```

```html
<button class="btnAnexos" data-id="42">Anexos</button>
```

### Vários campos de anexo no mesmo registo

Se um registo tiver mais que um "tipo" de anexo (ex.: "identidade" e
"comprovativo de residência", geridos separadamente) — passa `category`:

```javascript
$('#anexosIdentidade').uploadFile({
    category: 'identidade',
    listUrl: '/posts/attachments', uploadUrl: '/posts/attachments', deleteUrl: '/posts/attachments/remove',
    title: 'Documento de Identidade'
});

$('#anexosComprovativo').uploadFile({
    category: 'comprovativo',
    listUrl: '/posts/attachments', uploadUrl: '/posts/attachments', deleteUrl: '/posts/attachments/remove',
    title: 'Comprovativo de Residência'
});
```

O `category` viaja automaticamente em todos os pedidos (list/upload/delete)
— o controller de exemplo acima já lê `$request->input('category')` e
passa a `getFilesByReference(..., category: ...)`/`uploadFile(..., category: ...)`.

### Métodos públicos

| Método | Argumento | O que faz |
|---|---|---|
| `open` | `id` (opcional) | Abre o modal. Se passares um id, actualiza `referenceId` antes de abrir — é o que usas no padrão de "várias linhas" acima. |
| `close` | — | Fecha o modal. |
| `refresh` | — | Torna a pedir a lista a `listUrl`, sem fechar o modal. |
| `setReferenceId` | `id` | Muda o registo associado, sem abrir o modal. |
| `destroy` | — | Remove o plugin por completo. |


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
