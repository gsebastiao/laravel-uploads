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
    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],

    /*
    |--------------------------------------------------------------------------
    | Tamanho máximo (em KB)
    |--------------------------------------------------------------------------
    | Padrão de 10240 KB = 10 MB.
    */
    'max_size' => 10240,

    /*
    |--------------------------------------------------------------------------
    | Caminho base
    |--------------------------------------------------------------------------
    | Prefixo de diretório dentro do disco configurado. A estrutura final
    | fica: {base_path}/{group}/{YYYY}/{MM}/{uuid}.{ext}, onde {group} deriva
    | do tipo da entidade relacionada (ex.: "user") ou "shared" quando não há.
    */
    'base_path' => 'uploads',

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    | Geração automática de miniaturas para imagens.
    | method: fit (mantém proporção dentro da caixa), resize (força as
    | dimensões), crop (recorta ao centro).
    */
    'thumbnail' => [
        'enabled' => true,
        'width' => 120,
        'height' => 120,
        'quality' => 80,
        'method' => 'fit', // fit, resize, crop
    ],

    /*
    |--------------------------------------------------------------------------
    | Disco de armazenamento
    |--------------------------------------------------------------------------
    | Deve ser um disco definido em config/filesystems.php. O disco "public"
    | é o recomendado por gerar URLs acessíveis via /storage.
    */
    'disk' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Prefixo de URL
    |--------------------------------------------------------------------------
    | Usado como fallback quando o disco não expõe um método url(). Para o
    | disco "public" padrão, o Laravel já resolve via Storage::url().
    */
    'url_prefix' => '/storage',

    /*
    |--------------------------------------------------------------------------
    | Autoria dos uploads (uploaded_by)
    |--------------------------------------------------------------------------
    | Quando 'uploaded_by' não é passado explicitamente a uploadFile()/
    | uploadBase64(), o pacote tenta usar o utilizador autenticado no
    | momento. Desligar auto_detect_uploader torna esse comportamento
    | totalmente manual — uploaded_by fica sempre null a menos que seja
    | passado explicitamente. auth_guard escolhe qual guard verificar
    | (null usa o guard por omissão da aplicação, via auth()).
    */
    'auto_detect_uploader' => true,
    'auth_guard' => null,

];
