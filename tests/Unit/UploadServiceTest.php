<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Unit;

use Gsebastiao\LaravelUploads\Contracts\UploadInterface;
use Gsebastiao\LaravelUploads\Exceptions\ValidationException;
use Gsebastiao\LaravelUploads\Models\UploadFile;
use Gsebastiao\LaravelUploads\Tests\Fixtures\TestUser;
use Gsebastiao\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadServiceTest extends TestCase
{
    protected function service(): UploadInterface
    {
        return $this->app->make(UploadInterface::class);
    }

    public function test_uploads_a_valid_image_with_model_reference(): void
    {
        $user = TestUser::create(['name' => 'Ana']);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('avatar.png', 400, 400), $user);

        $this->assertInstanceOf(UploadFile::class, $model);
        $this->assertSame($user->getMorphClass(), $model->reference_type);
        $this->assertSame($user->id, $model->reference_id);
        $this->assertSame('png', $model->ext);
        $this->assertNotNull($model->width);
        Storage::disk('public')->assertExists($model->path);
    }

    public function test_uploads_with_type_and_id_pair(): void
    {
        $model = $this->service()->uploadFile(
            UploadedFile::fake()->image('a.png'),
            'App\\Models\\User',
            99
        );

        $this->assertSame('App\\Models\\User', $model->reference_type);
        $this->assertSame(99, $model->reference_id);
    }

    public function test_uploads_without_reference(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertNull($model->reference_type);
        $this->assertNull($model->reference_id);
        // Sem referência, o arquivo vai para o grupo "shared".
        $this->assertStringContainsString('/shared/', $model->path);
    }

    public function test_morph_relation_resolves_back_to_model(): void
    {
        $user = TestUser::create(['name' => 'Bea']);
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'), $user);

        $this->assertTrue($model->reference->is($user));
    }

    public function test_generates_thumbnail_for_images(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('photo.jpg', 800, 600));

        $this->assertNotNull($model->thumbnail);
        Storage::disk('public')->assertExists($model->thumbnail);
    }

    public function test_does_not_generate_thumbnail_for_non_images(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->create('document.pdf', 200, 'application/pdf'));

        $this->assertNull($model->thumbnail);
        $this->assertNull($model->width);
    }

    public function test_rejects_disallowed_mime_type(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->uploadFile(UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'));
    }

    public function test_rejects_file_larger_than_max_size(): void
    {
        config()->set('uploads.max_size', 100);
        $this->app->forgetInstance(UploadInterface::class);

        $this->expectException(ValidationException::class);

        $this->service()->uploadFile(UploadedFile::fake()->create('big.pdf', 500, 'application/pdf'));
    }

    public function test_get_file_returns_model_or_null(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertNotNull($this->service()->getFile($model->id));
        $this->assertNull($this->service()->getFile(999999));
    }

    public function test_soft_delete_and_permanent_delete(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));
        $path = $model->path;

        $this->assertTrue($this->service()->deleteFile($model->id));
        $this->assertSoftDeleted('uploads_files', ['id' => $model->id]);
        Storage::disk('public')->assertExists($path);

        $this->assertTrue($this->service()->deleteFile($model->id, true));
        $this->assertDatabaseMissing('uploads_files', ['id' => $model->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_delete_returns_false_for_missing_file(): void
    {
        $this->assertFalse($this->service()->deleteFile(123456));
    }

    public function test_get_files_by_reference_with_model(): void
    {
        $user = TestUser::create(['name' => 'Cid']);
        $other = TestUser::create(['name' => 'Dan']);
        $svc = $this->service();

        $svc->uploadFile(UploadedFile::fake()->image('1.png'), $user);
        $svc->uploadFile(UploadedFile::fake()->image('2.png'), $user);
        $svc->uploadFile(UploadedFile::fake()->image('3.png'), $other);

        $this->assertCount(2, $svc->getFilesByReference($user));
        $this->assertCount(1, $svc->getFilesByReference($other));
    }

    public function test_get_files_by_reference_with_type_only(): void
    {
        $svc = $this->service();
        $svc->uploadFile(UploadedFile::fake()->image('1.png'), 'App\\Models\\Post', 1);
        $svc->uploadFile(UploadedFile::fake()->image('2.png'), 'App\\Models\\Post', 2);

        // Sem id: todos do tipo Post.
        $this->assertCount(2, $svc->getFilesByReference('App\\Models\\Post'));
        // Com id específico.
        $this->assertCount(1, $svc->getFilesByReference('App\\Models\\Post', 1));
    }

    public function test_get_file_url_returns_public_url(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $url = $this->service()->getFileUrl($model);

        $this->assertStringContainsString($model->path, $url);
    }
}
