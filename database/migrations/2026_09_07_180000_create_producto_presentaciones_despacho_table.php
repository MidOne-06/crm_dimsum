<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Múltiplo/presentación de despacho por producto -- cuántas unidades se
 * despachan de una vez (ej. "múltiplos de 25"). Tabla dinámica editable
 * desde el panel a propósito: el usuario indicó explícitamente que esto
 * puede variar en el futuro, no es un valor fijo en código. La Directiva
 * de Transferencia la usa para redondear la cantidad sugerida al múltiplo
 * válido más cercano hacia arriba -- todavía no implementado, esta tabla
 * es la base de configuración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_presentaciones_despacho', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->unsignedInteger('multiplo')->default(1);
            $table->text('nota')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'item_tipo'], 'presentacion_despacho_item_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_presentaciones_despacho');
    }
};
