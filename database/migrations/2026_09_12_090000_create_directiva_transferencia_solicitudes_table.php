<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra un hueco funcional real de "Iniciar Directiva de Transferencia"
 * (2026-09-11/12, barrida pedida por el usuario): antes de esto, el único
 * disparador del cálculo tras sincronizar Kardex+Guías internas era el
 * propio `wire:poll` del navegador (`IniciarDirectivaTransferencia::
 * verificarSincronizacion()`) -- si el usuario cerraba la pestaña, perdía
 * conexión, o el celular mandaba la pestaña a segundo plano, las
 * sincronizaciones terminaban bien en el servidor pero la Directiva (con
 * el % de ajuste y alcance que la persona eligió) nunca se calculaba, sin
 * ningún aviso ni registro de que quedó a medias.
 *
 * Esta tabla persiste esa "intención de cálculo" server-side; un job en
 * cola (`CalcularDirectivaTrasSincronizacionJob`) se auto-reencola cada 30s
 * hasta que ambas sincronizaciones terminan (o detecta estancamiento) y
 * calcula por su cuenta -- ya no depende de que el navegador siga vivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directiva_transferencia_solicitudes', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha_referencia');
            $table->foreignId('kardex_extraccion_id')->nullable()->constrained('kardex_extracciones')->nullOnDelete();
            $table->foreignId('guia_sincronizacion_id')->nullable()->constrained('guias_internas_sincronizaciones')->nullOnDelete();
            $table->decimal('porcentaje_ajuste_global', 6, 2)->default(0);
            $table->json('porcentaje_ajuste_por_local')->nullable();
            $table->string('estado')->default('pendiente'); // pendiente | calculado | fallido | cancelado
            $table->string('mensaje_error')->nullable();
            $table->json('resultado')->nullable(); // {total, locales, fecha}
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directiva_transferencia_solicitudes');
    }
};
