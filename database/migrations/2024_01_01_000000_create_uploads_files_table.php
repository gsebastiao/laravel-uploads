<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::create('uploads_files', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Relação polimórfica: cria reference_type + reference_id e o
            // índice composto (reference_type, reference_id). Nullable para
            // permitir uploads sem entidade associada.
            $table->nullableMorphs('reference');

            $table->string('filename');
            $table->string('original_name');
            $table->string('path');
            $table->string('full_path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('ext', 20);
            $table->string('mime');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads_files');
    }
};
