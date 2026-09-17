<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Extensões permitidas
    |--------------------------------------------------------------------------
    | Lista de extensões aceitas no upload. A validação também confere o
    | MIME real do arquivo, não apenas a extensão informada.
    */
    // No .env, separe por vírgulas: UPLOADS_ALLOW_MIME=jpg,png,pdf
    'allowed_mimes' => array_values(array_filter(array_map('trim', explode(',', (string) env('UPLOADS_ALLOW_MIME', 'jpg,jpeg,png,gif,pdf,doc,docx'))))),

    /*
    |--------------------------------------------------------------------------
    | Tamanho máximo (em KB)
    |--------------------------------------------------------------------------
    | Padrão de 10240 KB = 10 MB.
    */
    'max_size' => env('UPLOADS_MAX_SIZE', 10240),

    /*
    |--------------------------------------------------------------------------
    | Caminho base
    |--------------------------------------------------------------------------
    | Prefixo de diretório dentro do disco configurado. A estrutura final
    | fica: {base_path}/{group}/{YYYY}/{MM}/{uuid}.{ext}, onde {group} deriva
    | do tipo da entidade relacionada (ex.: "user") ou "shared" quando não há.
    */
    'base_path' => env('UPLOADS_BASE_PATH', 'uploads'),

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    | Geração automática de miniaturas para imagens.
    | method: fit (mantém proporção dentro da caixa), resize (força as
    | dimensões), crop (recorta ao centro).
    */
    'thumbnail' => [
        'enabled' => env('UPLOADS_THUMBNAIL_ENABLE', true),
        'width' => env('UPLOADS_THUMBNAIL_WIDTH', 120),
        'height' => env('UPLOADS_THUMBNAIL_HEIGHT', 120),
        'quality' => env('UPLOADS_THUMBNAIL_QUALITY', 80),
        'method' => env('UPLOADS_THUMBNAIL_METHOD', 'fit'), // fit, resize, crop
    ],

    /*
    |--------------------------------------------------------------------------
    | Disco de armazenamento
    |--------------------------------------------------------------------------
    | Deve ser um disco definido em config/filesystems.php. O disco "public"
    | é o recomendado por gerar URLs acessíveis via /storage.
    */
    'disk' => env('UPLOADS_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Prefixo de URL
    |--------------------------------------------------------------------------
    | Usado como fallback quando o disco não expõe um método url(). Para o
    | disco "public" padrão, o Laravel já resolve via Storage::url().
    */
    'url_prefix' => env('UPLOADS_URL_PREFIX', '/storage'),

    /*
    |--------------------------------------------------------------------------
    | Destino do plugin JS ao publicar
    |--------------------------------------------------------------------------
    | Caminho relativo a public/, usado por:
    |   php artisan vendor:publish --tag=uploads-assets
    | Por omissão, o plugin é copiado para public/assets/js/upload-capture.js.
    */
    'assets_path' => env('UPLOADS_ASSET_PATH', 'assets/js'),

    /*
    |--------------------------------------------------------------------------
    | Autoria dos uploads (created_by)
    |--------------------------------------------------------------------------
    | Quando 'created_by' não é passado explicitamente a uploadFile()/
    | uploadBase64(), o pacote tenta usar o utilizador autenticado no
    | momento. Desligar auto_detect_uploader torna esse comportamento
    | totalmente manual — created_by fica sempre null a menos que seja
    | passado explicitamente. auth_guard escolhe qual guard verificar
    | (null usa o guard por omissão da aplicação, via auth()).
    */
    'auto_detect_uploader' => env('UPLOADS_AUTO_DETECT', true),
    'auth_guard' => env('UPLOADS_AUTH_GUARD', null),

    /*
    |--------------------------------------------------------------------------
    | Auditoria (integração opcional)
    |--------------------------------------------------------------------------
    | Quando 'enabled' está true, os registos de uploads (criação, atualização,
    | remoção) passam a ser auditados através do pacote opcional
    | gsebastiao/laravel-auditable (https://github.com/gsebastiao/laravel-auditable).
    |
    | IMPORTANTE: este pacote de uploads NÃO depende do laravel-auditable no
    | composer.json — é uma integração opcional. Se 'enabled' estiver true e
    | o pacote gsebastiao/laravel-auditable NÃO estiver instalado, a aplicação
    | falha ao arrancar com uma exceção clara (AuditPackageMissingException),
    | em vez de fingir que está a auditar sem gravar nada.
    |
    | Para ativar:
    |   composer require gsebastiao/laravel-auditable
    |   php artisan migrate
    |   (o config e a migration do auditable só se publicam para os editar)
    |   (depois, ligue 'enabled' abaixo, ou via AUDIT_UPLOADS_ENABLED no .env)
    */
    'audit' => [
        'enabled' => env('AUDIT_UPLOADS_ENABLED', false),
    ],

];
