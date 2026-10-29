<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Let's Encrypt account email for this server's Traefik.
            $table->string('acme_email')->nullable()->after('ssh_host_key_fingerprint');
            $table->unsignedBigInteger('uptime_seconds')->nullable()->after('disk_gb');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['acme_email', 'uptime_seconds']);
        });
    }
};
