<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table) {
            $table->json('header_map')->nullable()->after('stored_path');
            $table->json('checkpoint')->nullable()->after('partial_stats');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table) {
            $table->dropColumn(['header_map', 'checkpoint']);
        });
    }
};
