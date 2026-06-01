<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('is_primary');
            $table->text('error_message')->nullable()->after('status');
            $table->unsignedTinyInteger('attempts')->default(0)->after('error_message');
            $table->timestamp('queued_at')->nullable()->after('attempts');
            $table->timestamp('downloaded_at')->nullable()->after('queued_at');
        });

        \Illuminate\Support\Facades\DB::table('product_images')
            ->whereNotNull('path')
            ->update(['status' => 'completed', 'downloaded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_message', 'attempts', 'queued_at', 'downloaded_at']);
        });
    }
};
