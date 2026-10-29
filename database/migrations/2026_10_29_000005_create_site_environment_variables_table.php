<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_environment_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            // Always encrypted; is_sensitive only controls masking in the UI.
            $table->text('value')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();

            $table->unique(['site_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_environment_variables');
    }
};
