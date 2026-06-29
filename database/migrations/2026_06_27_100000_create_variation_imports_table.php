<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_imports', function (Blueprint $table) {
            $table->id();

            // Archivo fuente
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('disk')->default('local');

            // Estado general del proceso
            $table->string('status')->default('pending');
            // pending | validating | processing | completed | failed

            // Progreso
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_products')->default(0);
            $table->unsignedInteger('failed_products')->default(0);
            $table->unsignedInteger('skipped_products')->default(0);

            // Logs y reportes
            $table->json('log_entries')->nullable();       // log en tiempo real
            $table->string('error_report_path')->nullable(); // Excel de errores descargable
            $table->string('success_report_path')->nullable(); // Excel de exitosos

            // Checkpoint para reanudar si falla
            $table->json('checkpoint')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_imports');
    }
};
