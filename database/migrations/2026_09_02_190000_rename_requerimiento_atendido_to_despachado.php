<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Restaurant identifica el código 4 como “Despachado”. Las primeras
        // sincronizaciones lo guardaron con el nombre “Atendido”, por lo que
        // se normaliza el histórico para que el filtro y la fuente externa
        // utilicen la misma etiqueta.
        DB::table('requerimientos_stock_historicos')
            ->where('estado', 'Atendido')
            ->update(['estado' => 'Despachado', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('requerimientos_stock_historicos')
            ->where('estado', 'Despachado')
            ->update(['estado' => 'Atendido', 'updated_at' => now()]);
    }
};
