<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('host');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            // Encrypted with APP_KEY (see the model casts).
            $table->text('ssh_private_key');
            $table->text('ssh_key_passphrase')->nullable();
            // Pinned on the first successful connection; later connections
            // fail if the server presents a different key.
            $table->string('ssh_host_key_fingerprint')->nullable();

            $table->string('os_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('architecture')->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('disk_gb')->nullable();
            $table->string('docker_version')->nullable();
            $table->string('compose_version')->nullable();
            $table->timestamp('inspected_at')->nullable();

            $table->string('provisioning_status')->default('pending')->index();
            $table->timestamp('last_connected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['host', 'ssh_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
