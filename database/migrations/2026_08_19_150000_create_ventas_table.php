<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ventas')) {
            return;
        }

        Schema::create('ventas', function (Blueprint $table) {
            $table->string('venta_id')->primary();
            $table->timestampTz('venta_fecha')->index();
            $table->string('local_id')->nullable()->index();
            $table->string('local')->nullable();
            $table->string('cliente_id')->nullable();
            $table->string('cliente')->nullable();
            $table->string('cliente_ruc')->nullable();
            $table->string('comprobante_tipo')->nullable()->index();
            $table->string('comprobante_serie')->nullable();
            $table->string('comprobante_numero')->nullable();
            $table->string('moneda')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('impuestos', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('forma_pago')->nullable();
            $table->string('estado')->nullable()->index();
            $table->string('usuario')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
