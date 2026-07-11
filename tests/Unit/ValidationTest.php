<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Unit;

use Gsebastiao\LaravelUploads\Contracts\UploadInterface;
use Gsebastiao\LaravelUploads\Exceptions\UploadException;
use Gsebastiao\LaravelUploads\Exceptions\ValidationException;
use Gsebastiao\LaravelUploads\Tests\Fixtures\TestUser;
use Gsebastiao\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ValidationTest extends TestCase
{
    protected function service(): UploadInterface
    {
        return $this->app->make(UploadInterface::class);
    }

    /** Um PNG 1x1 válido, sem prefixo data URI. */
    protected function rawPngBase64(): string
    {
        return base64_encode((string) file_get_contents($this->pngFixture()));
    }

    protected function pngFixture(): string
    {
        $path = sys_get_temp_dir() . '/fixture_' . uniqid() . '.png';
        $img = imagecreatetruecolor(1, 1);
        imagefilledrectangle($img, 0, 0, 1, 1, imagecolorallocate($img, 255, 0, 0));
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    public function test_valid_file_passes_validation(): void
    {
        $this->assertTrue($this->service()->validateFile(UploadedFile::fake()->image('ok.png')));
    }

    public function test_base64_with_data_uri_prefix_and_model(): void
    {
        $user = TestUser::create(['name' => 'Eva']);
        $base64 = 'data:image/png;base64,' . $this->rawPngBase64();

        $model = $this->service()->uploadBase64($base64, $user);

        $this->assertSame('png', $model->ext);
        $this->assertSame('image/png', $model->mime);
        $this->assertSame($user->getMorphClass(), $model->reference_type);
        $this->assertSame($user->id, $model->reference_id);
        Storage::disk('public')->assertExists($model->path);
    }

    public function test_base64_without_prefix_detects_mime(): void
    {
        $model = $this->service()->uploadBase64($this->rawPngBase64());

        $this->assertSame('image/png', $model->mime);
        Storage::disk('public')->assertExists($model->path);
    }

    public function test_base64_with_type_id_and_custom_filename(): void
    {
        $base64 = 'data:image/png;base64,' . $this->rawPngBase64();

        $model = $this->service()->uploadBase64($base64, 'App\\Models\\Post', 7, 'capa.png');

        $this->assertSame('App\\Models\\Post', $model->reference_type);
        $this->assertSame(7, $model->reference_id);
        $this->assertSame('capa.png', $model->original_name);
    }

    public function test_invalid_base64_throws(): void
    {
        $this->expectException(UploadException::class);

        $this->service()->uploadBase64('!!!not-base64!!!');
    }

    public function test_base64_disallowed_mime_throws(): void
    {
        config()->set('uploads.allowed_mimes', ['pdf']);
        $this->app->forgetInstance(UploadInterface::class);

        $this->expectException(ValidationException::class);

        $base64 = 'data:image/png;base64,' . $this->rawPngBase64();
        $this->service()->uploadBase64($base64);
    }
}
