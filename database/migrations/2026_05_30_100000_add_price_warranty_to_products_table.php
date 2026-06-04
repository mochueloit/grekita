<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('price', 14, 2)->nullable()->after('brand');
            $table->decimal('price_foreign', 14, 2)->nullable()->after('price');
            $table->string('price_currency', 16)->nullable()->after('price_foreign');
            $table->string('warranty', 255)->nullable()->after('price_currency');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['price', 'price_foreign', 'price_currency', 'warranty']);
        });
    }
};
