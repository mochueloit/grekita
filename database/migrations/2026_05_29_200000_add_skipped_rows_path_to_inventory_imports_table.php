<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table) {
            $table->string('skipped_rows_path')->nullable()->after('checkpoint');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table) {
            $table->dropColumn('skipped_rows_path');
        });
    }
};
