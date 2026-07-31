<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Feature;

use Gsebastiao\LaravelUploads\Facades\Upload;
use Gsebastiao\LaravelUploads\Http\Traits\HandlesFileAttachments;
use Gsebastiao\LaravelUploads\Models\UploadFile;
use Gsebastiao\LaravelUploads\Tests\Fixtures\TestUser;
use Gsebastiao\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;

/**
 * Testa HandlesFileAttachments através de rotas que a exercitam
 * exactamente como um controller real faria.
 */
class HandlesFileAttachmentsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $controller = new class {
            use HandlesFileAttachments;

            public function list()
            {
                return $this->handleAttachmentsList(request(), TestUser::class);
            }

            public function upload()
            {
                return $this->handleAttachmentUpload(request(), TestUser::class);
            }

            public function delete()
            {
                return $this->handleAttachmentsDelete(request());
            }

            public function downloadAll()
            {
                return $this->handleAttachmentsDownloadAll(request(), TestUser::class);
            }
        };

        $router->get('/attachments', [$controller, 'list']);
        $router->post('/attachments', [$controller, 'upload']);
        $router->post('/attachments/remove', [$controller, 'delete']);
        $router->get('/attachments/download-all', [$controller, 'downloadAll']);
    }

    public function test_list_returns_files_for_reference(): void
    {
        $user = TestUser::create(['name' => 'Lista']);
        Upload::uploadFile(UploadedFile::fake()->image('a.png'), $user);
        Upload::uploadFile(UploadedFile::fake()->image('b.png'), $user);

        $response = $this->getJson('/attachments?id=' . $user->id);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertCount(2, $response->json('files'));
    }

    public function test_list_filters_by_category(): void
    {
        $user = TestUser::create(['name' => 'Cat']);
        Upload::uploadFile(UploadedFile::fake()->image('a.png'), $user, category: 'identidade');
        Upload::uploadFile(UploadedFile::fake()->image('b.png'), $user, category: 'comprovativo');

        $response = $this->getJson('/attachments?id=' . $user->id . '&category=identidade');

        $this->assertCount(1, $response->json('files'));
        $this->assertSame('identidade', $response->json('files.0.category'));
    }

    public function test_upload_creates_file_and_returns_it(): void
    {
        $user = TestUser::create(['name' => 'Up']);

        $response = $this->postJson('/attachments', [
            'id' => $user->id,
            'file' => UploadedFile::fake()->image('novo.png'),
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('novo.png', $response->json('file.name'));
        $this->assertSame(1, UploadFile::count());
    }

    public function test_upload_auto_detects_authenticated_user_as_uploaded_by(): void
    {
        $user = TestUser::create(['name' => 'Auth']);
        $this->actingAs($user);

        $response = $this->postJson('/attachments', [
            'id' => $user->id,
            'file' => UploadedFile::fake()->image('a.png'),
        ]);

        $this->assertSame($user->id, $response->json('file.uploaded_by'));
    }

    public function test_delete_removes_given_ids(): void
    {
        $user = TestUser::create(['name' => 'Del']);
        $file = Upload::uploadFile(UploadedFile::fake()->image('a.png'), $user);

        $response = $this->postJson('/attachments/remove', ['ids' => [$file->id]]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSoftDeleted('uploads_files', ['id' => $file->id]);
    }

    public function test_download_all_returns_a_zip_file(): void
    {
        $user = TestUser::create(['name' => 'Zip']);
        Upload::uploadFile(UploadedFile::fake()->image('a.png'), $user);
        Upload::uploadFile(UploadedFile::fake()->image('b.png'), $user);

        $response = $this->get('/attachments/download-all?id=' . $user->id);

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
    }

    public function test_download_all_returns_404_when_no_files(): void
    {
        $user = TestUser::create(['name' => 'Vazio']);

        $response = $this->getJson('/attachments/download-all?id=' . $user->id);

        $response->assertStatus(404)->assertJson(['success' => false]);
    }
}
