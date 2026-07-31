<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Http\Traits;

use Gsebastiao\LaravelUploads\Facades\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Lógica de servidor para o fluxo "staged" (plugins uploadCapture,
 * singleCapture, multipleCapture — ficheiros embutidos num formulário,
 * só chegam ao servidor quando o formulário inteiro é submetido).
 *
 * Para o fluxo imediato (plugin uploadFile), ver HandlesFileAttachments —
 * são fluxos conceptualmente diferentes, tal como já são dois mundos
 * separados no lado do JS.
 *
 * Uso: `use HandlesStagedFileUploads;` no teu controller, e chama
 * handleFilesPreview()/handleFilesUpload() a partir dos teus métodos. Ver
 * o README, secção "uploadCapture", para um exemplo completo.
 */
trait HandlesStagedFileUploads
{
    /**
     * Devolve os ficheiros já existentes de um registo, e opcionalmente os
     * dados do próprio registo — pensado para popular o formulário e o
     * widget ao reabrir um registo para editar.
     *
     * @param callable(int): mixed|null $fetchRecord Função que recebe o id e devolve o registo (ou null para não incluir 'table' na resposta).
     */
    protected function handleFilesPreview(Request $request, string $modelClass, ?callable $fetchRecord = null): JsonResponse
    {
        $request->validate(['id' => ['required', 'integer']]);

        $referenceId = $request->input('id');
        $record = $fetchRecord ? $fetchRecord($referenceId) : null;
        $uploadedFiles = Upload::getFilesByReference($modelClass, $referenceId);

        return response()->json([
            'success' => true,
            'table' => $record,
            'files' => $uploadedFiles->map(fn ($file) => Upload::getFileUrl($file)),
        ]);
    }

    /**
     * Processa a submissão de um formulário com ficheiros staged: campos
     * extra do registo (validados e passados a $updateRecord), ficheiros
     * novos (multipart e/ou base64), e a remoção de ficheiros já
     * existentes que a pessoa tenha removido no ecrã (detectado por
     * diferença contra 'files_paths[]' — o que já lá estava mas já não
     * vem nesse campo foi removido).
     *
     * Tudo dentro de uma transação: se o registo ou algum upload falhar a
     * meio, os ficheiros já gravados nesta chamada são apagados antes de
     * devolver o erro — não ficam órfãos.
     *
     * @param array<string, array<int, mixed>> $fieldRules Regras de validação dos campos do registo (além de 'id', que já é tratado).
     * @param callable(int, array<string, mixed>): bool $updateRecord Função que recebe o id e os campos validados, e devolve true/false.
     * @param list<string> $allowedMimes Extensões aceites. [] = qualquer tipo.
     */
    protected function handleFilesUpload(
        Request $request,
        string $modelClass,
        array $fieldRules,
        callable $updateRecord,
        array $allowedMimes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'],
        int $maxSizeKb = 2048
    ): JsonResponse {
        $request->validate(array_merge(['id' => ['required', 'integer']], $fieldRules));

        $referenceId = $request->input('id');
        $extraFields = $request->only(array_keys($fieldRules));

        $realFiles = collect($request->file('files', []))
            ->filter(fn ($f) => $f !== null && $f->isValid() && $f->getSize() > 0)
            ->values();

        $base64Items = collect($request->input('files_base64', []))
            ->filter(fn ($item) => is_string($item) && $item !== '')
            ->values();

        $base64Names = collect($request->input('files_base64_names', []))->values();

        // Ficheiros pré-existentes que sobreviveram no ecrã (não foram removidos).
        $keptPaths = collect($request->input('files_paths', []))
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->values();

        $existingFiles = Upload::getFilesByReference($modelClass, $referenceId);
        $filesToDelete = $existingFiles->filter(
            fn ($file) => ! $keptPaths->contains(Upload::getFileUrl($file))
        );

        if ($realFiles->isEmpty() && $base64Items->isEmpty() && $keptPaths->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'O registo tem de ficar com pelo menos um ficheiro.'], 422);
        }

        $mimesRule = empty($allowedMimes) ? 'file' : 'mimes:' . implode(',', $allowedMimes);

        foreach ($realFiles as $index => $file) {
            $validator = Validator::make(['file' => $file], ['file' => ['file', $mimesRule, 'max:' . $maxSizeKb]]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => "Ficheiro #{$index}: " . $validator->errors()->first()], 422);
            }
        }

        foreach ($base64Items as $index => $item) {
            if (! preg_match('/^data:([\w.+-]+\/[\w.+-]+);base64,([A-Za-z0-9+\/=]+)$/', $item, $m)) {
                return response()->json(['success' => false, 'message' => "Captura #{$index}: formato base64 inválido."], 422);
            }

            $ext = $this->extensionFromMime($m[1]);

            if (! empty($allowedMimes) && ! in_array($ext, $allowedMimes, true)) {
                return response()->json(['success' => false, 'message' => "Captura #{$index}: tipo não permitido ({$m[1]})."], 422);
            }

            if ((strlen($m[2]) * 3 / 4) > $maxSizeKb * 1024) {
                return response()->json(['success' => false, 'message' => "Captura #{$index}: excede o tamanho máximo ({$maxSizeKb}KB)."], 422);
            }
        }

        $uploadedIds = [];

        try {
            $result = DB::transaction(function () use (
                $referenceId, $extraFields, $realFiles, $base64Items, $base64Names, $modelClass, $updateRecord, &$uploadedIds
            ) {
                $updated = $updateRecord($referenceId, $extraFields);

                if (! $updated) {
                    throw new \RuntimeException('Falha ao actualizar o registo.');
                }

                $saved = [];

                foreach ($realFiles as $file) {
                    $uploadFile = Upload::uploadFile($file, $modelClass, $referenceId);
                    $uploadedIds[] = $uploadFile->id;
                    $saved[] = $uploadFile;
                }

                foreach ($base64Items as $index => $item) {
                    preg_match('/^data:([\w.+-]+\/[\w.+-]+);base64,/', $item, $m);
                    $ext = $this->extensionFromMime($m[1] ?? 'application/octet-stream');
                    $filename = $base64Names->get($index) ?: 'captura_' . $referenceId . '_' . $index . '.' . $ext;

                    $uploadFile = Upload::uploadBase64($item, $modelClass, $referenceId, filename: $filename);
                    $uploadedIds[] = $uploadFile->id;
                    $saved[] = $uploadFile;
                }

                return collect($saved);
            });

            // Só apaga os removidos DEPOIS da transação confirmar com sucesso,
            // nunca dentro dela — deleteFile() apaga do disco, o que não é
            // transacional; um rollback nunca devolveria um ficheiro já apagado.
            foreach ($filesToDelete as $file) {
                try {
                    Upload::deleteFile($file->id, true);
                } catch (\Exception $deleteError) {
                    Log::error('Falha ao apagar ficheiro removido pelo utilizador', [
                        'upload_id' => $file->id, 'error' => $deleteError->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'urls' => $result->map(fn ($f) => Upload::getFileUrl($f)),
                'removed' => $filesToDelete->count(),
            ]);
        } catch (\Exception $e) {
            foreach ($uploadedIds as $id) {
                try {
                    Upload::deleteFile($id, true);
                } catch (\Exception $cleanupError) {
                    Log::error('Falha ao limpar ficheiro órfão após rollback', [
                        'upload_id' => $id, 'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Deriva a extensão a partir do mime, para nomear ficheiros base64
     * sem nome original (ex.: capturas de câmara). Cobre os tipos mais
     * comuns; para mimes desconhecidos usa o subtipo como aproximação.
     */
    protected function extensionFromMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
            'image/gif' => 'gif', 'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
        ];

        return $map[$mime] ?? (explode('/', $mime)[1] ?? 'bin');
    }
}
