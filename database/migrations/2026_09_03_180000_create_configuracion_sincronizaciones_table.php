<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel de sincronización: hasta ahora, activar/desactivar un sync
 * automático (Guías/Salidas/Requerimientos/Stock Actual/Kardex/Ventas)
 * significaba editar routes/console.php y hacer un deploy -- pedido
 * explícito del usuario: que sea dinámico, gestionable desde la propia UI,
 * sin tocar código para prender o apagar uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_sincronizaciones', function (Blueprint $table): void {
            $table->id();
            $table->string('modulo')->unique();
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->string('cron_expresion')->nullable();
            $table->string('desactivado_por')->nullable();
            $table->timestamp('desactivado_en')->nullable();
            $table->text('nota')->nullable();
            $table->timestamps();
        });

        // Semilla: los 6 módulos conocidos, todos activos por defecto (el
        // estado real hoy -- nadie apagó nada todavía).
        $modulos = [
            ['modulo' => 'stock-actual', 'nombre' => 'Stock Actual', 'cron_expresion' => '*/30 * * * *'],
            ['modulo' => 'salidas-stock', 'nombre' => 'Salidas de stock', 'cron_expresion' => '0 * * * *'],
            ['modulo' => 'guias-internas', 'nombre' => 'Guías internas', 'cron_expresion' => '*/30 * * * *'],
            ['modulo' => 'requerimientos-stock', 'nombre' => 'Requerimientos de stock', 'cron_expresion' => '*/30 * * * *'],
            ['modulo' => 'kardex', 'nombre' => 'Kardex', 'cron_expresion' => '5 0 * * *'],
            ['modulo' => 'ventas', 'nombre' => 'Ventas', 'cron_expresion' => '0 0 * * *'],
        ];

        foreach ($modulos as $modulo) {
            DB::table('configuracion_sincronizaciones')->insert($modulo + [
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_sincronizaciones');
    }
};
