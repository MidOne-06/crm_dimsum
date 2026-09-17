<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_payload_archivos', function (Blueprint $table): void {
            $table->id();
            $table->string('venta_id')->unique();
            $table->string('disk');
            $table->string('path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes_originales');
            $table->unsignedBigInteger('bytes_comprimidos');
            $table->string('formato')->default('json.gz');
            $table->timestampTz('archivado_en');
            $table->timestampTz('verificado_en')->nullable();
            $table->timestampsTz();

            $table->foreign('venta_id')->references('venta_id')->on('ventas')->cascadeOnDelete();
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_payload_archivos');
    }
};
