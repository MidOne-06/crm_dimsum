<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de TODA la configuración de la Directiva de Transferencia
 * (tapers + los 9 módulos de casuística operativa): quién creó/editó/borró
 * qué fila, cuándo, y con qué valores antes/después. Una sola tabla
 * compartida (no una por módulo) para poder auditar todo el conjunto desde
 * un solo lugar -- ver App\Models\Concerns\RegistraTrazabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_dt_eventos', function (Blueprint $table): void {
            $table->id();
            $table->string('tabla');
            $table->unsignedBigInteger('registro_id');
            $table->enum('accion', ['creado', 'actualizado', 'eliminado']);
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('usuario_nombre')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tabla', 'registro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_dt_eventos');
    }
};
