<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion_diaria_cierres', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha')->unique();
            $table->string('area')->default('FABRICA');
            $table->string('estado')->default('borrador')->index(); // borrador | enviado | aprobado
            $table->text('observacion')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_en')->nullable();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_en')->nullable();
            $table->timestamps();
        });

        Schema::create('produccion_diaria_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cierre_id')->constrained('produccion_diaria_cierres')->cascadeOnDelete();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->string('unidad')->nullable();
            $table->decimal('stock_inicial', 14, 4)->default(0);
            $table->decimal('producido_hoy', 14, 4)->default(0);
            $table->decimal('stock_esperado', 14, 4)->default(0);
            $table->decimal('stock_final', 14, 4)->nullable();
            $table->decimal('diferencia', 14, 4)->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();
            $table->unique(['cierre_id', 'item_id', 'item_tipo'], 'produccion_diaria_detalle_unico');
        });

        Schema::create('produccion_diaria_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cierre_id')->constrained('produccion_diaria_cierres')->cascadeOnDelete();
            $table->string('accion'); // creado | actualizado | enviado | aprobado
            $table->jsonb('antes')->nullable();
            $table->jsonb('despues')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_diaria_auditorias');
        Schema::dropIfExists('produccion_diaria_detalles');
        Schema::dropIfExists('produccion_diaria_cierres');
    }
};
