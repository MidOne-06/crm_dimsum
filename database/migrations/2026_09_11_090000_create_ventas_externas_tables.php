<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canales_ventas_externas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->boolean('activo')->default(true)->index();
            $table->timestampsTz();
        });

        Schema::create('cuotas_ventas_externas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('canal_id')->constrained('canales_ventas_externas')->cascadeOnDelete();
            $table->date('periodo')->index();
            $table->decimal('cuota_sin_igv', 14, 2)->default(0);
            $table->decimal('cuota_con_igv', 14, 2)->default(0);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['canal_id', 'periodo']);
        });

        Schema::create('ventas_externas_diarias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('canal_id')->constrained('canales_ventas_externas')->restrictOnDelete();
            $table->date('fecha')->index();
            $table->decimal('venta_sin_igv', 14, 2);
            $table->decimal('igv', 14, 2);
            $table->decimal('venta_con_igv', 14, 2);
            $table->unsignedInteger('tickets')->default(0);
            $table->decimal('costo_sin_igv', 14, 2);
            $table->text('observacion')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->string('estado')->default('activa')->index();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['canal_id', 'fecha']);
        });

        Schema::create('venta_externa_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venta_externa_diaria_id')->constrained('ventas_externas_diarias')->cascadeOnDelete();
            $table->string('accion');
            $table->jsonb('antes')->nullable();
            $table->jsonb('despues')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('cuota_venta_externa_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuota_venta_externa_id')->constrained('cuotas_ventas_externas')->cascadeOnDelete();
            $table->jsonb('antes')->nullable();
            $table->jsonb('despues');
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        $now = now();
        $canales = [
            ['codigo' => 'VW001', 'nombre' => 'VENTA WHATSAPP', 'cuota_sin_igv' => 6000, 'cuota_con_igv' => 6630],
            ['codigo' => 'TOF099', 'nombre' => 'VENTA OFICINA', 'cuota_sin_igv' => 9000, 'cuota_con_igv' => 9945],
            ['codigo' => 'B2B001', 'nombre' => 'CENCOSUD', 'cuota_sin_igv' => 40000, 'cuota_con_igv' => 44200],
            ['codigo' => 'B2B002', 'nombre' => 'OXXO', 'cuota_sin_igv' => 90000, 'cuota_con_igv' => 99450],
        ];

        foreach ($canales as $canal) {
            DB::table('canales_ventas_externas')->updateOrInsert(
                ['codigo' => $canal['codigo']],
                ['nombre' => $canal['nombre'], 'activo' => true, 'created_at' => $now, 'updated_at' => $now],
            );

            $canalId = DB::table('canales_ventas_externas')->where('codigo', $canal['codigo'])->value('id');
            DB::table('cuotas_ventas_externas')->updateOrInsert(
                ['canal_id' => $canalId, 'periodo' => '2026-09-01'],
                ['cuota_sin_igv' => $canal['cuota_sin_igv'], 'cuota_con_igv' => $canal['cuota_con_igv'], 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cuota_venta_externa_auditorias');
        Schema::dropIfExists('venta_externa_auditorias');
        Schema::dropIfExists('ventas_externas_diarias');
        Schema::dropIfExists('cuotas_ventas_externas');
        Schema::dropIfExists('canales_ventas_externas');
    }
};
