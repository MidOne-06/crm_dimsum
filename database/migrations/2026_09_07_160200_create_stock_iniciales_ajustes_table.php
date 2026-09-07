<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección auditada posterior a la carga confirmada -- nunca se
 * sobreescribe `stock_iniciales_detalles.cantidad_inicial` directamente
 * (eso reescribiría en silencio el histórico del saldo ya calculado). Un
 * ajuste es un movimiento más, con motivo y autor, sumado aparte en el
 * recálculo del saldo. Requiere permiso separado (`stock-inicial.ajustar`)
 * del de la carga inicial (`stock-inicial.crear`) -- segregación de
 * funciones real, no solo nominal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_iniciales_ajustes', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_nombre')->nullable();
            $table->decimal('cantidad_ajuste', 14, 4); // puede ser negativo
            $table->text('motivo');
            $table->foreignId('ajustado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ajustado_en');
            $table->timestamps();

            $table->index(['local_id', 'item_id', 'item_tipo'], 'stock_ajuste_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_iniciales_ajustes');
    }
};
