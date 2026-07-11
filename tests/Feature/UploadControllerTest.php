<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Feature;

use Gsebastiao\LaravelUploads\Facades\Upload;
use Gsebastiao\LaravelUploads\Models\UploadFile;
use Gsebastiao\LaravelUploads\Tests\Fixtures\TestUser;
use Gsebastiao\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;

/**
 * Testa o fluxo ponta-a-ponta através de uma rota que reproduz o
 * controller de exemplo documentado no README.
 */
class UploadControllerTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/users/{userId}/avatar', function ($userId) {
            request()->validate([
                'avatar' => 'required|file|mimes:jpg,jpeg,png|max:2048',
            ]);

            // Reproduz o uso com tipo + id (sem carregar o model).
            $file = Upload::uploadFile(request()->file('avatar'), TestUser::class, (int) $userId);

            return response()->json([
                'success' => true,
                'data' => $file,
                'url' => Upload::getFileUrl($file),
            ]);
        });
    }

    public function test_uploads_avatar_through_route(): void
    {
        $response = $this->postJson('/users/15/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.reference_type', TestUser::class)
            ->assertJsonPath('data.reference_id', 15);

        $this->assertIsString($response->json('url'));
        $this->assertSame(1, UploadFile::count());
    }

    public function test_rejects_invalid_mime_through_validation(): void
    {
        $response = $this->postJson('/users/1/avatar', [
            'avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, UploadFile::count());
    }

    public function test_facade_get_and_delete_with_model(): void
    {
        $user = TestUser::create(['name' => 'Fai']);
        $model = Upload::uploadFile(UploadedFile::fake()->image('x.png'), $user);

        $this->assertNotNull(Upload::getFile($model->id));
        $this->assertTrue(Upload::deleteFile($model->id));
        $this->assertSoftDeleted('uploads_files', ['id' => $model->id]);
    }
}
