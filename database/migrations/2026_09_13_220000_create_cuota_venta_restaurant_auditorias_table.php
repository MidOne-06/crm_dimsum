<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuota_venta_restaurant_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuota_venta_restaurant_id')->constrained('cuotas_ventas_restaurant')->cascadeOnDelete();
            $table->string('accion');
            $table->jsonb('antes')->nullable();
            $table->jsonb('despues');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuota_venta_restaurant_auditorias');
    }
};
