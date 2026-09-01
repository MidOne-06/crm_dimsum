<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('requerimientos_stock_historicos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('erp_id')->unique();
            $table->timestamp('fecha_registro')->nullable()->index();
            $table->timestamp('fecha_abastecimiento')->nullable()->index();
            $table->string('solicitado_por')->nullable()->index();
            $table->string('local_produccion')->nullable()->index();
            $table->string('encargado')->nullable();
            $table->string('receptor')->nullable();
            $table->string('estado')->nullable()->index();
            $table->text('observacion')->nullable();
            $table->timestamp('sincronizado_en')->index();
            $table->timestamps();
        });

        Schema::create('requerimientos_stock_historicos_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requerimiento_stock_historico_id')->constrained('requerimientos_stock_historicos')->cascadeOnDelete();
            $table->string('erp_detalle_id');
            $table->string('codigo')->nullable()->index();
            $table->string('item')->nullable();
            $table->string('categoria')->nullable();
            $table->string('presentacion')->nullable();
            $table->decimal('cantidad_solicitada', 18, 4)->default(0);
            $table->decimal('cantidad_despachada', 18, 4)->default(0);
            $table->decimal('cantidad_preparada', 18, 4)->default(0);
            $table->string('unidad')->nullable();
            $table->string('almacen')->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();
            $table->unique(['requerimiento_stock_historico_id', 'erp_detalle_id'], 'req_stock_historico_detalle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requerimientos_stock_historicos_detalles');
        Schema::dropIfExists('requerimientos_stock_historicos');
    }
};
