<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Http\Traits;

use Gsebastiao\LaravelUploads\Facades\Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lógica de servidor para o fluxo imediato (plugin uploadFile — gestor de
 * anexos em modal próprio, cada acção fala logo com o servidor, sem
 * esperar por nenhum submit de formulário).
 *
 * Para o fluxo staged (plugins uploadCapture, singleCapture,
 * multipleCapture), ver HandlesStagedFileUploads — são fluxos
 * conceptualmente diferentes, tal como já são dois mundos separados no
 * lado do JS.
 *
 * Uso: `use HandlesFileAttachments;` no teu controller, e liga cada
 * método a um dos quatro URLs que o uploadFile espera (listUrl,
 * uploadUrl, deleteUrl, downloadAllUrl). Ver o README, secção
 * "uploadFile", para o formato exacto de cada pedido/resposta.
 */
trait HandlesFileAttachments
{
    /**
     * Responde a listUrl — lista os anexos de um registo, com filtro de
     * categoria opcional.
     */
    protected function handleAttachmentsList(Request $request, string $modelClass): JsonResponse
    {
        $request->validate([
            'id' => ['required', 'integer'],
            'category' => ['nullable', 'string'],
        ]);

        $referenceId = $request->input('id');
        $category = $request->input('category');

        $files = Upload::getFilesByReference($modelClass, $referenceId, category: $category);

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
                'category' => $file->category,
            ])->values(),
        ]);
    }

    /**
     * Responde a uploadUrl — grava um novo anexo imediatamente.
     * uploaded_by não precisa de ser passado à mão: o próprio serviço já
     * detecta o utilizador autenticado sozinho (configurável via
     * config('uploads.auto_detect_uploader')/('uploads.auth_guard')).
     *
     * @param list<string> $allowedMimes Extensões aceites.
     */
    protected function handleAttachmentUpload(
        Request $request,
        string $modelClass,
        array $allowedMimes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'],
        int $maxSizeKb = 2048
    ): JsonResponse {
        $request->validate([
            'id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:' . implode(',', $allowedMimes), 'max:' . $maxSizeKb],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        $referenceId = $request->input('id');
        $category = $request->input('category');

        try {
            $file = Upload::uploadFile($request->file('file'), $modelClass, $referenceId, category: $category);

            return response()->json([
                'success' => true,
                'file' => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'url' => Upload::getFileUrl($file),
                    'mime' => $file->mime,
                    'size' => $file->size,
                    'uploaded_at' => optional($file->created_at)->toDateTimeString(),
                    'uploaded_by' => $file->uploaded_by,
                    'category' => $file->category,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Responde a deleteUrl — remove um ou vários anexos, em definitivo.
     */
    protected function handleAttachmentsDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $failed = [];

        foreach ($request->input('ids') as $id) {
            try {
                Upload::deleteFile($id, true);
            } catch (\Exception $e) {
                $failed[] = $id;
                Log::error('Falha ao apagar anexo', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }

        if (! empty($failed)) {
            return response()->json([
                'success' => false,
                'message' => 'Alguns ficheiros não puderam ser removidos: ' . implode(', ', $failed),
            ], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Responde a downloadAllUrl — gera um .zip com todos os anexos de um
     * registo (respeitando o filtro de categoria, se dado) e devolve-o
     * directamente. Ficheiros já não presentes fisicamente em disco são
     * ignorados, não interrompem o resto do zip.
     */
    protected function handleAttachmentsDownloadAll(Request $request, string $modelClass): JsonResponse|BinaryFileResponse
    {
        $request->validate([
            'id' => ['required', 'integer'],
            'category' => ['nullable', 'string'],
        ]);

        $referenceId = $request->input('id');
        $category = $request->input('category');

        $files = Upload::getFilesByReference($modelClass, $referenceId, category: $category);

        if ($files->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Sem ficheiros para descarregar.'], 404);
        }

        $disk = config('uploads.disk', 'public');
        $zipName = 'anexos_' . $referenceId . '_' . now()->format('YmdHis') . '.zip';
        $zipPath = storage_path('app/tmp/' . $zipName);

        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['success' => false, 'message' => 'Não foi possível criar o zip.'], 500);
        }

        $usedNames = [];
        foreach ($files as $file) {
            $absolutePath = Storage::disk($disk)->path($file->path);

            if (! is_file($absolutePath)) {
                continue; // ficheiro já não existe fisicamente — ignora, não interrompe o resto
            }

            $name = $file->original_name ?: basename($file->path);
            $i = 1;
            while (in_array($name, $usedNames, true)) {
                $ext = pathinfo($file->original_name, PATHINFO_EXTENSION);
                $stem = pathinfo($file->original_name, PATHINFO_FILENAME);
                $name = $stem . ' (' . $i . ')' . ($ext ? '.' . $ext : '');
                $i++;
            }
            $usedNames[] = $name;

            $zip->addFile($absolutePath, $name);
        }

        $zip->close();

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }
}
