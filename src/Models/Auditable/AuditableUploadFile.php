<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Models\Auditable;

use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\LaravelUploads\Models\UploadFile;

/**
 * Variante auditável do UploadFile.
 *
 * ESTE ARQUIVO SÓ PODE SER CARREGADO QUANDO gsebastiao/laravel-auditable
 * ESTIVER INSTALADO — importa `Gsebastiao\Auditable\Concerns\Auditable` no
 * topo do arquivo, então um `use` (import) desta classe falharia com
 * "Class not found" caso o pacote não exista. Por isso:
 *
 *  - Esta classe NUNCA é referenciada directamente (via `use`/import) em
 *    código que corre sempre (UploadService, Facade, migrations). É
 *    resolvida apenas pelo nome, através de
 *    AuditSupport::uploadFileModelClass(), e só depois de
 *    AuditSupport::packageInstalled() confirmar que o pacote existe.
 *  - Continua a estender UploadFile (e não Model directamente), para herdar
 *    toda a estrutura de tabela, casts, fillable e relações sem duplicação —
 *    apenas adiciona o comportamento de auditoria por cima.
 *
 * getAuditOptions() define como os campos internos (path completo, tamanho
 * em bytes, etc.) aparecem no log de auditoria — mantendo-o legível, e
 * evitando duplicar o conteúdo binário/paths sensíveis do disco em texto
 * plano no histórico.
 */
class AuditableUploadFile extends UploadFile
{
    // Nota sobre precedência: métodos definidos num trait usado pela própria
    // classe têm prioridade sobre métodos herdados da classe pai (regra do
    // PHP). Por isso auditAction()/auditFailure() aqui vêm efetivamente do
    // trait Auditable (que os implementa de verdade), e não dos no-ops
    // definidos em UploadFile — sem precisar de os redeclarar aqui.
    use Auditable;

    /**
     * Opções de auditoria para os uploads.
     *
     * - Ignora 'full_path' (caminho absoluto no disco do servidor — detalhe
     *   de infraestrutura, não de negócio, e potencialmente sensível).
     * - Regista create/update/delete (omissão do pacote), incluindo o
     *   retrato de restauro no delete (permite recuperar metadados do
     *   arquivo mesmo após um hard delete).
     */
    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults()
            ->except(['full_path']);
    }
}
