<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de ediciones de cantidad en el canje de guías internas -- pedido
 * explícito del usuario (2026-09-13): al reportar que `applyGuideQuantityOverrides`
 * (API-TI) no topaba la cantidad editada contra la de la guía original, el
 * usuario confirmó que recibir MÁS cantidad de la que trae la guía SÍ es un
 * caso real de su operación -- no hay que bloquearlo. Lo que sí pidió es
 * dejar trazabilidad real de cada edición: quién, cuándo, de cuánto a
 * cuánto. `applyGuideQuantityOverrides()` ahora devuelve ese detalle
 * (`overrides`) junto con el resultado del canje -- esta tabla lo persiste,
 * para los 3 flujos que terminan llamando al mismo `confirmGuideExchange`
 * del gateway (canje individual, canje masivo por selección manual, y el
 * canje masivo filtrado de "Canjear todo lo filtrado").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canje_guia_cantidad_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->json('guia_ids');
            $table->string('movimiento_id')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_descripcion')->nullable();
            $table->decimal('cantidad_original', 14, 4);
            $table->decimal('cantidad_confirmada', 14, 4);
            $table->string('origen'); // individual | masivo_seleccion | masivo_filtrado
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canje_guia_cantidad_auditorias');
    }
};
