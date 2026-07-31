# Changelog

Todas as alterações notáveis deste projecto serão documentadas neste ficheiro.

O formato baseia-se em [Keep a Changelog](https://keepachangelog.com/pt/1.0.0/),
e este projecto adere a [Semantic Versioning](https://semver.org/lang/pt/).

## [1.1.0]

### Adicionado

- Campo `category` em uploads — agrupa ficheiros dentro da mesma referência (ex.: vários campos de anexo distintos associados ao mesmo registo, como "identidade" e "comprovativo de residência"). Filtrável via `getFilesByReference($reference, category: '...')`.
- Campo `uploaded_by` — regista automaticamente o utilizador autenticado no momento do upload, salvo passagem explícita de outro valor.
- `UploadFile::uploader()` — relação `BelongsTo` que resolve o model de User dinamicamente via `config('auth.providers.users.model')`, sem assumir `App\Models\User`.
- Configuração `auto_detect_uploader` (bool, omissão `true`) — desliga por completo a detecção automática de `uploaded_by`.
- Configuração `auth_guard` (string|null, omissão `null`) — escolhe qual guard de autenticação verificar; `null` usa o guard por omissão da aplicação.
- `upload-capture.example.js` — plugins jQuery de referência (`singleCapture`, `multipleCapture`, `uploadCapture`, `uploadFile`), totalmente documentados via JSDoc, incluídos no repositório como ponto de partida opcional para quem não quer construir a interface do zero. Não é publicado automaticamente por `vendor:publish`.
- Secção "Quickstart" no README — exemplo completo de controller, rotas e vista Blade.
- 13 novos testes, cobrindo `category`, `uploaded_by`, e as duas configurações novas.

### Alterado

- `uploadFile()`, `uploadBase64()` e `getFilesByReference()` — na interface (`UploadInterface`), no serviço (`UploadService`) e no facade (`Upload`) — ganharam parâmetros opcionais novos (`category`, `uploadedBy`). Compatível com chamadas existentes: os parâmetros novos são opcionais e vêm sempre no fim, nenhum parâmetro anterior mudou de posição, tipo ou comportamento.
- `UploadFile::$fillable` e `casts()` — incluem agora `category` e `uploaded_by`.

### Nota para quem actualizar a partir da v1.0.0

As colunas novas foram incluídas directamente na migration original de
criação da tabela (`..._create_uploads_files_table`), em vez de virem numa
migration própria. Como o Laravel regista migrations pelo nome do ficheiro,
não pelo conteúdo, quem já tiver corrido a migration da v1.0.0 precisa de
uma migration própria, na aplicação, para trazer as colunas novas:

```bash
php artisan make:migration add_category_and_uploaded_by_to_uploads_files_table
```

```php
public function up(): void
{
    Schema::table('uploads_files', function (Blueprint $table) {
        $table->string('category')->nullable()->after('reference_id');
        $table->unsignedBigInteger('uploaded_by')->nullable()->after('category');
        $table->index('category');
        $table->index('uploaded_by');
    });
}

public function down(): void
{
    Schema::table('uploads_files', function (Blueprint $table) {
        $table->dropIndex(['category']);
        $table->dropIndex(['uploaded_by']);
        $table->dropColumn(['category', 'uploaded_by']);
    });
}
```

Instalações novas (a partir da v1.1.0) não precisam disto — a migration do
próprio pacote já cria as colunas desde o início.

## [1.0.0]

Primeiro lançamento público.
