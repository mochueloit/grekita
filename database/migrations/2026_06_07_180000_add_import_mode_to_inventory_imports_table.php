<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table): void {
            $table->string('import_mode', 32)->default('full')->after('disk');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table): void {
            $table->dropColumn('import_mode');
        });
    }
};
