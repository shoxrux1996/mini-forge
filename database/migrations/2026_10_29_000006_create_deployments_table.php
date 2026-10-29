<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger')->default('manual');
            $table->string('status')->default('pending')->index();
            $table->string('branch')->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->string('image')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });

        Schema::create('deployment_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->string('status')->default('pending');
            // Redacted and truncated; full container logs stay on the server.
            $table->text('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_steps');
        Schema::dropIfExists('deployments');
    }
};
