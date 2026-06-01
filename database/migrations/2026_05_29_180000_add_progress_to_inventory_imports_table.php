<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table) {
            $table->unsignedInteger('total_rows')->nullable()->after('status');
            $table->unsignedInteger('processed_rows')->default(0)->after('total_rows');
            $table->string('current_step')->nullable()->after('processed_rows');
            $table->json('partial_stats')->nullable()->after('stats');
            $table->json('log_entries')->nullable()->after('partial_stats');
            $table->timestamp('last_activity_at')->nullable()->after('log_entries');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table) {
            $table->dropColumn([
                'total_rows',
                'processed_rows',
                'current_step',
                'partial_stats',
                'log_entries',
                'last_activity_at',
            ]);
        });
    }
};
