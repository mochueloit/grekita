<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table): void {
            $table->string('wp_sync_log_path')->nullable()->after('image_download_log_path');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table): void {
            $table->dropColumn('wp_sync_log_path');
        });
    }
};
