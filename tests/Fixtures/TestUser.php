<?php

declare(strict_types=1);

namespace Gsebastiao\LaravelUploads\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Model mínimo usado como entidade de referência nos testes de morph.
 */
class TestUser extends Model
{
    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = false;
}
