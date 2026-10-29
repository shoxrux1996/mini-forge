<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Registry host as Docker names it: ghcr.io, docker.io, localhost:5000.
            $table->string('host');
            $table->string('username');
            $table->text('password'); // encrypted; a token with push and pull access
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            // Null: push with your local Docker login, pull anonymously.
            $table->foreignId('registry_id')->nullable()->after('image_repository')->constrained()->restrictOnDelete();
        });

        Schema::table('deployments', function (Blueprint $table) {
            // What was running before, so a failed release can switch back.
            $table->string('previous_image')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', fn (Blueprint $table) => $table->dropColumn('previous_image'));
        Schema::table('sites', fn (Blueprint $table) => $table->dropConstrainedForeignId('registry_id'));
        Schema::dropIfExists('registries');
    }
};
