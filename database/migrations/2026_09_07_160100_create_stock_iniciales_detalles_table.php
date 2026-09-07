<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `item_id` NO es único por sí solo en Restaurant -- confirmado con data
 * real de kardex_movimientos: el mismo item_id se reutiliza para productos
 * distintos según `tipo_item` (ej. id=130 es "BOLSA 16 X 19" tipo
 * DESCARTABLE y también "Vale De Consumo" tipo PRODUCTO, superpuestos en
 * el tiempo). La clave real de un ítem en todo este módulo es SIEMPRE
 * item_id + item_tipo -- mismo patrón que ya usa MovimientosAlmacenes.php
 * (`item_tipo:item_id:...`). Nunca usar item_id solo para identificar un
 * producto en las tablas de este módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_iniciales_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_inicial_local_id')->constrained('stock_iniciales_locales')->cascadeOnDelete();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre')->nullable();
            $table->string('unidad')->nullable();
            $table->decimal('cantidad_inicial', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['stock_inicial_local_id', 'item_id', 'item_tipo'], 'stock_inicial_detalle_item_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_iniciales_detalles');
    }
};
