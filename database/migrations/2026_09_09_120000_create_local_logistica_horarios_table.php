<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende local_logistica_configs.hora_llegada_estimada (1 sola hora fija
 * por local) para que la hora de llegada pueda variar según el día de la
 * semana -- pedido explícito del usuario (2026-09-09): un mismo local puede
 * recibir su camión más temprano un día y más tarde otro. Tabla aparte (no
 * 7 columnas en local_logistica_configs) para poder cargar solo los días
 * que realmente tienen una excepción -- un local sin ninguna fila acá usa
 * simplemente hora_llegada_estimada (o 12:00 si tampoco tiene eso) para
 * los 7 días, ver DirectivaTransferenciaService::horaLlegada().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_logistica_horarios', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id');
            $table->unsignedTinyInteger('dia_semana'); // 1=lunes .. 7=domingo, igual que ISODOW de Postgres
            $table->time('hora');
            $table->timestamps();
            $table->unique(['local_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_logistica_horarios');
    }
};
