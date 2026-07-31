# Changelog

Todas as alterações notáveis deste projecto serão documentadas neste ficheiro.

O formato baseia-se em [Keep a Changelog](https://keepachangelog.com/pt/1.0.0/),
e este projecto adere a [Semantic Versioning](https://semver.org/lang/pt/).

## [Não lançado]

### Adicionado

- `HandlesStagedFileUploads` (`Gsebastiao\LaravelUploads\Http\Traits`) — trait pronta para o fluxo `uploadCapture`/`singleCapture`/`multipleCapture` (formulário, staged até ao submit). Cobre `handleFilesPreview()` e `handleFilesUpload()`, incluindo a lógica de detectar ficheiros removidos por diferença contra `files_paths[]`.
- `HandlesFileAttachments` (`Gsebastiao\LaravelUploads\Http\Traits`) — trait pronta para o fluxo `uploadFile` (modal, acção imediata). Cobre os quatro endpoints (`handleAttachmentsList`, `handleAttachmentUpload`, `handleAttachmentsDelete`, `handleAttachmentsDownloadAll`), já com `category`/`uploaded_by` ligados ao serviço.
- 13 novos testes de Feature, cobrindo as duas traits através de rotas reais (não só chamadas directas aos métodos).

### Notas

Estas duas traits substituem, em espírito, os exemplos de controller que
já estavam no README — continuam lá, agora também descritos como "o que a
trait faz por dentro", para quem quiser perceber o funcionamento ou
precisar de algo diferente do que elas oferecem.

## [1.2.0]

### Adicionado

- Campo `category` em uploads — agrupa ficheiros dentro da mesma referência (ex.: vários campos de anexo distintos associados ao mesmo registo, como "identidade" e "comprovativo de residência"). Filtrável via `getFilesByReference($reference, category: '...')`.
- Campo `uploaded_by` — regista automaticamente o utilizador autenticado no momento do upload, salvo passagem explícita de outro valor.
- `UploadFile::uploader()` — relação `BelongsTo` que resolve o model de User dinamicamente via `config('auth.providers.users.model')`, sem assumir `App\Models\User`.
- Configuração `auto_detect_uploader` (bool, omissão `true`) — desliga por completo a detecção automática de `uploaded_by`.
- Configuração `auth_guard` (string|null, omissão `null`) — escolhe qual guard de autenticação verificar; `null` usa o guard por omissão da aplicação.
- Plugins jQuery de referência (`singleCapture`, `multipleCapture`, `uploadCapture`, `uploadFile`), totalmente documentados via JSDoc — `src/Plugin/upload-capture.init.js`, publicável via `php artisan vendor:publish --tag=uploads-assets` (destino configurável, `config('uploads.assets_path')`, omissão `assets/js`).
- Secção "Quickstart" no README — exemplo completo de controller, rotas e vista Blade, depois expandida numa secção própria por plugin (`uploadCapture`, `uploadFile`), com tabela de parâmetros e o formato exacto de cada pedido/resposta.
- 13 novos testes, cobrindo `category`, `uploaded_by`, e as duas configurações novas.
- `php artisan vendor:publish --tag=uploads-upgrade-1.2.0` — migration de upgrade para quem já tinha a v1.0.0 instalada (ver nota abaixo).
- `setImages()`/`addImage()`, em `singleCapture` e `multipleCapture`, passam a aceitar objectos `{src, name, mime}` (além de strings simples, que continuam a funcionar) — o nome real do ficheiro passa a aparecer na grelha e na pré-visualização também para ficheiros já existentes, não só para os recém-escolhidos.

### Alterado

- `uploadFile()`, `uploadBase64()` e `getFilesByReference()` — na interface (`UploadInterface`), no serviço (`UploadService`) e no facade (`Upload`) — ganharam parâmetros opcionais novos (`category`, `uploadedBy`). Compatível com chamadas existentes: os parâmetros novos são opcionais e vêm sempre no fim, nenhum parâmetro anterior mudou de posição, tipo ou comportamento.
- `UploadFile::$fillable` e `casts()` — incluem agora `category` e `uploaded_by`.

### Nota para quem actualizar a partir da v1.0.0

As colunas novas foram incluídas directamente na migration original de
criação da tabela (`..._create_uploads_files_table`), em vez de virem numa
migration própria. Como o Laravel regista migrations pelo nome do ficheiro,
não pelo conteúdo, quem já tiver corrido a migration da v1.0.0 precisa de
trazer as colunas novas por outra via. A mais simples:

```bash
php artisan vendor:publish --tag=uploads-upgrade-1.2.0
php artisan migrate
```

Isto publica uma migration própria (com timestamp fresco, corre depois das
que já tens) e segura de correr mesmo em duplicado — tem guardas
`Schema::hasColumn()`, por isso não rebenta mesmo que as colunas já
existam por algum motivo.

Instalações novas (a partir da v1.1.0) não precisam disto — a migration do
próprio pacote já cria as colunas desde o início.

## [1.0.0]

Primeiro lançamento público.
