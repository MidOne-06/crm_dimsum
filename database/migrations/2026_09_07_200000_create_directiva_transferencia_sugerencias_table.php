<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cantidad sugerida a despachar, por local x ítem x fecha de despacho.
 * Universo de ítems = los que tienen `producto_presentaciones_despacho`
 * (son los que realmente se despachan a diario); universo de locales = los
 * que tienen `stock_iniciales_locales` confirmado (sin eso no hay saldo
 * real para restar). Se recalcula por completo cada vez que corre el
 * comando -- no se acumula histórico versionado por corrida todavía, cada
 * fecha tiene una sola fila vigente por local+ítem (se sobreescribe si se
 * recalcula la misma fecha dos veces).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directiva_transferencia_sugerencias', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha_despacho');
            $table->unsignedTinyInteger('dia_semana'); // 1=lunes .. 7=domingo (isoWeekday)
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre')->nullable();
            $table->decimal('demanda_promedio', 14, 4)->default(0);
            $table->unsignedTinyInteger('semanas_consideradas')->default(0);
            $table->decimal('saldo_actual', 14, 4)->default(0);
            $table->decimal('cantidad_bruta', 14, 4)->default(0);
            $table->unsignedInteger('multiplo_aplicado')->default(1);
            $table->unsignedInteger('cantidad_sugerida')->default(0);
            $table->timestamp('calculado_en')->nullable();
            $table->timestamps();

            $table->unique(['fecha_despacho', 'local_id', 'item_id', 'item_tipo'], 'directiva_sugerencia_unica');
            $table->index('fecha_despacho');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directiva_transferencia_sugerencias');
    }
};
