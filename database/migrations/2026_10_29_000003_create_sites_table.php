<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // Compose project name and directory under /opt/mini-forge/sites.
            $table->string('slug');
            $table->string('domain')->unique();
            $table->string('repository_url');
            $table->string('branch')->default('main');
            $table->string('php_version')->default('8.4');
            $table->string('web_runtime')->default('frankenphp');
            $table->string('image_source')->default('build');
            // Registry repository without a tag, e.g. ghcr.io/acme/shop.
            $table->string('image_repository');
            $table->string('current_image')->nullable();
            $table->boolean('run_migrations')->default(true);
            $table->string('health_check_path')->default('/up');
            $table->string('status')->default('pending')->index();
            $table->timestamps();

            $table->unique(['server_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
