<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // The site's own logical database in the server's shared Redis.
            $table->unsignedSmallInteger('redis_database')->after('health_check_path');
            $table->unique(['server_id', 'redis_database']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'redis_database']);
            $table->dropColumn('redis_database');
        });
    }
};
