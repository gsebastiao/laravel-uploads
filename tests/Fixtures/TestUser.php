<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Fixtures;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Model mínimo usado como entidade de referência nos testes de morph.
 *
 * Implementa Authenticatable para poder ser usado com $this->actingAs()
 * nos testes de uploaded_by (UploadService::resolveUploadedBy() usa auth()).
 */
class TestUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = false;
}
