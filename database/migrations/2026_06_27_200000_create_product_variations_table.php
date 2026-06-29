<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->string('sku_padre')->index();
            $table->string('sku')->unique();
            $table->string('letra');
            $table->json('atributos');
            $table->unsignedInteger('stock_482845934')->default(0)->comment('Lechería');
            $table->unsignedInteger('stock_7196119')->default(0)->comment('Caracas');
            $table->unsignedInteger('stock_82385465')->default(0)->comment('Puerto Ordaz');
            $table->unsignedInteger('stock_total')->default(0);
            $table->unsignedBigInteger('inventory_import_id')->nullable()->index();
            $table->string('wc_status')->default('pending')->comment('pending|synced|failed');
            $table->unsignedBigInteger('wc_variation_id')->nullable();
            $table->text('wc_error')->nullable();
            $table->timestamp('wc_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('inventory_import_id')
                ->references('id')->on('inventory_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variations');
    }
};
