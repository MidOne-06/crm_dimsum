<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las corridas previas se escribieron mientras PHP usaba America/Lima y
     * PostgreSQL interpretaba los valores sin offset como UTC. Son instantes
     * cinco horas anteriores al real. Se corrigen únicamente las marcas de
     * auditoría de extracciones de guías, que son las usadas para progreso,
     * detección de estancamiento e historial de este módulo.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update guias_internas_sincronizaciones
            set
                created_at = created_at + interval '5 hours',
                updated_at = updated_at + interval '5 hours',
                iniciado_en = iniciado_en + interval '5 hours',
                completado_en = completado_en + interval '5 hours'
            SQL);
    }

    public function down(): void
    {
        // La corrección representa el instante real de eventos históricos y
        // no debe revertirse automáticamente.
    }
};
