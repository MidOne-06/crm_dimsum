<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario (2026-09-13): "cuando cada local realice su
 * sugerido, debe quedar como histórico". Hasta ahora, `directiva_ajustes_
 * local_detalles` es el estado VIVO de un pedido -- corregirlo (mientras
 * está 'pendiente') o volver a enviarlo tras un rechazo lo SOBREESCRIBE
 * (`updateOrCreate`), y retirarlo (pedir 0 múltiplos) lo BORRA -- en ambos
 * casos se pierde el rastro real de qué pasó (motivo del rechazo, cuándo,
 * qué había pedido antes de corregir). Esta tabla es un registro de solo
 * inserción (nunca se actualiza ni se borra) con una fila por cada
 * transición real (creado/editado/retirado/aprobado/rechazado) -- así el
 * historial completo de un local sobrevive aunque la fila "viva" cambie o
 * desaparezca. Deliberadamente SIN llave foránea a
 * directiva_ajustes_local_detalles/solicitudes (que si se cascadearan,
 * borrarían el historial justo cuando el detalle se retira -- el caso que
 * más importa conservar): queda autocontenido con sus propios datos
 * desnormalizados, igual que el resto de las auditorías del proyecto
 * (venta_externa_auditorias, canje_guia_cantidad_auditorias).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directiva_ajustes_local_historial', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->date('fecha_despacho');
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_nombre');
            $table->string('accion'); // creado | editado | retirado | aprobado | rechazado
            $table->integer('multiplos_solicitados')->nullable();
            $table->decimal('delta_unidades', 14, 4)->nullable();
            $table->text('motivo')->nullable();
            $table->text('comentario_admin')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['local_id', 'fecha_despacho']);
            $table->index(['item_id', 'item_tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directiva_ajustes_local_historial');
    }
};
