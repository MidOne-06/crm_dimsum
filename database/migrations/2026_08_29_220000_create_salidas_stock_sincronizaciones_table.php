<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salidas_stock_sincronizaciones', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha_inicio')->index();
            $table->date('fecha_fin')->index();
            $table->string('estado')->default('pendiente')->index();
            $table->unsignedInteger('paginas_total')->default(0);
            $table->unsignedInteger('paginas_procesadas')->default(0);
            $table->unsignedInteger('cabeceras_guardadas')->default(0);
            $table->unsignedInteger('detalles_guardados')->default(0);
            $table->unsignedInteger('cabeceras_eliminadas')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('iniciado_en')->nullable();
            $table->timestampTz('completado_en')->nullable();
            $table->timestampsTz();
        });

        Schema::table('salidas_stock', function (Blueprint $table): void {
            $table->foreignId('sincronizacion_id')->nullable()->after('id')->constrained('salidas_stock_sincronizaciones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salidas_stock', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sincronizacion_id');
        });
        Schema::dropIfExists('salidas_stock_sincronizaciones');
    }
};
