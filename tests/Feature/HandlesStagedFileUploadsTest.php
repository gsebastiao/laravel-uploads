<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Feature;

use Gsebastiao\LaravelUploads\Facades\Upload;
use Gsebastiao\LaravelUploads\Http\Traits\HandlesStagedFileUploads;
use Gsebastiao\LaravelUploads\Models\UploadFile;
use Gsebastiao\LaravelUploads\Tests\Fixtures\TestUser;
use Gsebastiao\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;

/**
 * Testa HandlesStagedFileUploads através de uma rota que a exercita
 * exactamente como um controller real faria.
 */
class HandlesStagedFileUploadsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $controller = new class {
            use HandlesStagedFileUploads;

            public function preview()
            {
                return $this->handleFilesPreview(
                    request(),
                    TestUser::class,
                    fn ($id) => TestUser::find($id)
                );
            }

            public function upload()
            {
                return $this->handleFilesUpload(
                    request(),
                    TestUser::class,
                    ['name' => ['required', 'string']],
                    fn ($id, $fields) => TestUser::where('id', $id)->update($fields)
                );
            }

            public function uploadAlwaysFails()
            {
                return $this->handleFilesUpload(
                    request(),
                    TestUser::class,
                    ['name' => ['required', 'string']],
                    fn ($id, $fields) => false // simula falha ao actualizar o registo
                );
            }
        };

        $router->get('/staged/preview', [$controller, 'preview']);
        $router->post('/staged/upload', [$controller, 'upload']);
        $router->post('/staged/upload-fails', [$controller, 'uploadAlwaysFails']);
    }

    public function test_preview_returns_record_and_existing_files(): void
    {
        $user = TestUser::create(['name' => 'Preview']);
        Upload::uploadFile(UploadedFile::fake()->image('a.png'), $user);

        $response = $this->getJson('/staged/preview?id=' . $user->id);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame($user->id, $response->json('table.id'));
        $this->assertCount(1, $response->json('files'));
    }

    public function test_upload_creates_files_and_updates_record(): void
    {
        $user = TestUser::create(['name' => 'Antes']);

        $response = $this->postJson('/staged/upload', [
            'id' => $user->id,
            'name' => 'Depois',
            'files' => [UploadedFile::fake()->image('a.png')],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('Depois', $user->fresh()->name);
        $this->assertSame(1, UploadFile::count());
    }

    public function test_upload_with_base64_uses_given_name(): void
    {
        $user = TestUser::create(['name' => 'X']);
        $base64 = 'data:image/png;base64,' . base64_encode('conteudo-fake');

        $response = $this->postJson('/staged/upload', [
            'id' => $user->id,
            'name' => 'X',
            'files_base64' => [$base64],
            'files_base64_names' => ['contrato.png'],
        ]);

        $response->assertOk();
        $this->assertSame('contrato.png', UploadFile::first()->original_name);
    }

    public function test_upload_removes_files_not_present_in_kept_paths(): void
    {
        $user = TestUser::create(['name' => 'X']);
        $kept = Upload::uploadFile(UploadedFile::fake()->image('kept.png'), $user);
        $removed = Upload::uploadFile(UploadedFile::fake()->image('removed.png'), $user);

        $response = $this->postJson('/staged/upload', [
            'id' => $user->id,
            'name' => 'X',
            // Só o "kept" continua presente — o "removed" foi tirado do ecrã.
            'files_paths' => [Upload::getFileUrl($kept)],
        ]);

        $response->assertOk()->assertJsonPath('removed', 1);
        $this->assertNotNull(UploadFile::find($kept->id));
        $this->assertNull(UploadFile::find($removed->id)); // apagado em definitivo
    }

    public function test_upload_rejects_empty_submission(): void
    {
        $user = TestUser::create(['name' => 'X']);

        $response = $this->postJson('/staged/upload', [
            'id' => $user->id,
            'name' => 'X',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_upload_rolls_back_and_cleans_up_when_record_update_fails(): void
    {
        $user = TestUser::create(['name' => 'X']);

        $response = $this->postJson('/staged/upload-fails', [
            'id' => $user->id,
            'name' => 'X',
            'files' => [UploadedFile::fake()->image('a.png')],
        ]);

        $response->assertStatus(500)->assertJson(['success' => false]);
        // O ficheiro chegou a ser gravado antes da falha, mas foi limpo.
        $this->assertSame(0, UploadFile::count());
    }
}
