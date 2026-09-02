<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 de la Directiva de Transferencia, casuística operativa (más allá de
 * tapers, ya construidos): siete módulos de configuración, todos
 * administrables y dinámicos por decisión explícita del usuario -- ver
 * sección 05 del artifact "Directiva de Transferencia" para el detalle de
 * cada uno y por qué existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Cadencia, hora de llegada, ventana de recepción, inactividad
        // temporal y modo de arranque -- todo por local, una sola pantalla
        // (mismo criterio que local_taper_capacidades: un local, varias
        // propiedades operativas).
        Schema::create('local_logistica_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id')->unique();
            $table->string('local_nombre');
            $table->unsignedTinyInteger('frecuencia_dias')->default(1); // 1=diario, 2=cada 2 días...
            $table->time('hora_llegada_estimada')->nullable();
            $table->time('ventana_recepcion_inicio')->nullable();
            $table->time('ventana_recepcion_fin')->nullable();
            $table->date('inactivo_desde')->nullable();
            $table->date('inactivo_hasta')->nullable();
            $table->string('inactivo_motivo')->nullable();
            $table->enum('modo_arranque', ['gemelo', 'estandar', 'manual'])->default('manual');
            $table->string('local_gemelo_id')->nullable(); // usado solo si modo_arranque = 'gemelo'
            $table->timestamps();
        });

        // 2. Vida útil por producto (días antes de perder calidad/vencer).
        Schema::create('producto_vida_utils', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id')->unique();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->unsignedSmallInteger('dias_vida_util');
            $table->timestamps();
        });

        // 3. Techo de producción diaria de FABRICA por producto. El
        // histórico de lo REALMENTE producido no se duplica aquí -- se lee
        // directo de kardex_movimientos (local_nombre = 'FABRICA', columna
        // entrada) para comparar contra este techo declarado.
        Schema::create('fabrica_capacidad_productos', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id')->unique();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->decimal('capacidad_maxima_dia', 12, 2);
            $table->timestamps();
        });

        // 4. Estrategia de prorrateo ante escasez de origen -- una fila
        // única (fila 1, patrón "settings"), más la lista de prioridad
        // manual cuando aplica.
        Schema::create('configuracion_prorrateos', function (Blueprint $table): void {
            $table->id();
            $table->enum('estrategia', ['proporcional_venta', 'orden_llegada', 'menor_stock', 'manual'])->default('proporcional_venta');
            $table->timestamps();
        });

        Schema::create('prioridad_local_prorrateos', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id')->unique();
            $table->string('local_nombre');
            $table->unsignedInteger('orden'); // menor = más prioridad
            $table->timestamps();
        });

        // 5. Reglas de sustitución entre presentaciones/productos cuando
        // falta la exacta en origen.
        Schema::create('regla_sustitucion_productos', function (Blueprint $table): void {
            $table->id();
            $table->string('item_original_id');
            $table->string('item_original_nombre');
            $table->string('item_sustituto_id');
            $table->string('item_sustituto_nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['item_original_id', 'item_sustituto_id']);
        });

        // 6. Capacidad máxima por vehículo/viaje (catálogo chico, como
        // taper_tipos -- puede haber más de un tipo de vehículo).
        Schema::create('vehiculo_capacidads', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->unsignedInteger('capacidad_maxima_tapers');
            $table->timestamps();
        });

        // 7. Cantidad estándar de arranque por producto (vía "estandar" del
        // modo_arranque de local_logistica_configs, y también usable para
        // producto nuevo sin historial en ningún local).
        Schema::create('cantidad_estandar_arranques', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id')->unique();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->decimal('cantidad_arranque', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cantidad_estandar_arranques');
        Schema::dropIfExists('vehiculo_capacidads');
        Schema::dropIfExists('regla_sustitucion_productos');
        Schema::dropIfExists('prioridad_local_prorrateos');
        Schema::dropIfExists('configuracion_prorrateos');
        Schema::dropIfExists('fabrica_capacidad_productos');
        Schema::dropIfExists('producto_vida_utils');
        Schema::dropIfExists('local_logistica_configs');
    }
};
