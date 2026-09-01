<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base física para la Directiva de Transferencia (Fase 0): los productos de
 * despacho central se guardan y transportan en "tapers" -- contenedores con
 * nombre propio (chico, grande, ...) cuya capacidad en unidades depende del
 * producto (el mismo producto puede tener presentaciones distintas), y cada
 * local tiene un máximo de tapers de cada tipo que caben en su congeladora.
 * Esta capacidad no se sincroniza desde Restaurant.pe -- no existe ahí -- la
 * carga y mantiene el administrador a mano en CRM DIMSUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taper_tipos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('producto_tapers', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id')->index();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->foreignId('taper_tipo_id')->constrained('taper_tipos')->cascadeOnDelete();
            $table->unsignedInteger('capacidad_unidades');
            $table->timestamps();
            $table->unique(['item_id', 'taper_tipo_id']);
        });

        Schema::create('local_taper_capacidades', function (Blueprint $table): void {
            $table->id();
            $table->string('local_id')->index();
            $table->string('local_nombre');
            $table->foreignId('taper_tipo_id')->constrained('taper_tipos')->cascadeOnDelete();
            $table->unsignedInteger('capacidad_maxima');
            $table->timestamps();
            $table->unique(['local_id', 'taper_tipo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_taper_capacidades');
        Schema::dropIfExists('producto_tapers');
        Schema::dropIfExists('taper_tipos');
    }
};
