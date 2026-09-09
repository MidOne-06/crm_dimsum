<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días de la semana en que un local NO genera Directiva de Transferencia
 * -- pedido explícito del usuario (2026-09-09), con un caso real concreto:
 * un local puede no generar DT los sábados (por el motivo operativo que
 * sea), lo que hace que el domingo no llegue ningún transporte nuevo (nada
 * se despachó el sábado para eso) -- el despacho del VIERNES tiene que
 * cubrir sábado Y domingo, hasta que llegue lo que sí se despache el
 * domingo (para el lunes). Puede aplicar a 1 local, varios, o todos, y
 * a cualquier día de la semana, no solo sábado.
 *
 * Ver DirectivaTransferenciaService::proximaLlegada() para cómo esta tabla
 * cambia el cálculo de las fechas de destino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_dias_sin_dt', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id');
            $table->unsignedTinyInteger('dia_semana'); // 1=lunes .. 7=domingo, igual que ISODOW
            $table->timestamps();
            $table->unique(['local_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_dias_sin_dt');
    }
};
