<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docker_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->boolean('enabled')->default(true);
            // Overrides the role's default command when set.
            $table->string('command')->nullable();
            $table->string('memory_limit')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docker_services');
    }
};
