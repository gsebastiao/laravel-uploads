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

    public function test_persists_category_when_provided(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'), null, null, category: 'identidade');

        $this->assertSame('identidade', $model->category);
    }

    public function test_category_is_null_when_not_provided(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertNull($model->category);
    }

    public function test_get_files_by_reference_filters_by_category(): void
    {
        $user = TestUser::create(['name' => 'Eva']);
        $svc = $this->service();

        $svc->uploadFile(UploadedFile::fake()->image('1.png'), $user, category: 'identidade');
        $svc->uploadFile(UploadedFile::fake()->image('2.png'), $user, category: 'comprovativo');
        $svc->uploadFile(UploadedFile::fake()->image('3.png'), $user, category: 'identidade');

        $this->assertCount(2, $svc->getFilesByReference($user, category: 'identidade'));
        $this->assertCount(1, $svc->getFilesByReference($user, category: 'comprovativo'));
        // Sem filtro de categoria: devolve todos, como antes desta feature existir.
        $this->assertCount(3, $svc->getFilesByReference($user));
    }

    public function test_uploaded_by_uses_explicit_value_when_given(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'), null, null, uploadedBy: 42);

        $this->assertSame(42, $model->uploaded_by);
    }

    public function test_uploaded_by_defaults_to_authenticated_user(): void
    {
        $user = TestUser::create(['name' => 'Fio']);
        $this->actingAs($user);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertSame($user->id, $model->uploaded_by);
    }

    public function test_explicit_uploaded_by_takes_precedence_over_authenticated_user(): void
    {
        $user = TestUser::create(['name' => 'Hugo']);
        $this->actingAs($user);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'), null, null, uploadedBy: 999);

        $this->assertSame(999, $model->uploaded_by);
    }

    public function test_uploaded_by_is_null_without_explicit_value_or_authenticated_user(): void
    {
        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertNull($model->uploaded_by);
    }

    public function test_auto_detect_uploader_disabled_ignores_authenticated_user(): void
    {
        $user = TestUser::create(['name' => 'Ivo']);
        $this->actingAs($user);

        config()->set('uploads.auto_detect_uploader', false);
        $this->app->forgetInstance(UploadInterface::class);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertNull($model->uploaded_by);
    }

    public function test_auto_detect_uploader_disabled_still_honors_explicit_value(): void
    {
        config()->set('uploads.auto_detect_uploader', false);
        $this->app->forgetInstance(UploadInterface::class);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'), null, null, uploadedBy: 55);

        $this->assertSame(55, $model->uploaded_by);
    }

    public function test_uses_configured_auth_guard(): void
    {
        $user = TestUser::create(['name' => 'Joana']);
        $this->actingAs($user, 'custom');

        config()->set('uploads.auth_guard', 'custom');
        $this->app->forgetInstance(UploadInterface::class);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertSame($user->id, $model->uploaded_by);
    }

    public function test_uploader_relation_resolves_authenticated_user(): void
    {
        $user = TestUser::create(['name' => 'Gil']);
        $this->actingAs($user);

        $model = $this->service()->uploadFile(UploadedFile::fake()->image('a.png'));

        $this->assertTrue($model->uploader->is($user));
    }

    public function test_base64_upload_persists_category_and_uploaded_by(): void
    {
        $base64 = 'data:image/png;base64,' . base64_encode('conteudo-fake-de-imagem');

        $model = $this->service()->uploadBase64($base64, null, null, category: 'contrato', uploadedBy: 7);

        $this->assertSame('contrato', $model->category);
        $this->assertSame(7, $model->uploaded_by);
    }
}
