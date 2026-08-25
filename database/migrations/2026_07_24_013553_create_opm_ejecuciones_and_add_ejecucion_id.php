<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opm_ejecuciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parametro_id')->constrained('opm_parametros')->cascadeOnDelete();
            $table->string('estado')->default('ejecutando');
            $table->integer('total_productos')->default(0);
            $table->integer('total_precios')->default(0);
            $table->integer('total_detalles')->default(0);
            $table->timestampTz('iniciado_at')->useCurrent();
            $table->timestampTz('completado_at')->nullable();
            $table->timestamps();
        });

        Schema::table('opm_productos', function (Blueprint $table) {
            $table->foreignId('ejecucion_id')->nullable()->after('parametro_id')
                ->constrained('opm_ejecuciones')->nullOnDelete();
        });
        Schema::table('opm_precios', function (Blueprint $table) {
            $table->foreignId('ejecucion_id')->nullable()->after('parametro_id')
                ->constrained('opm_ejecuciones')->nullOnDelete();
        });
        Schema::table('opm_detalles', function (Blueprint $table) {
            $table->foreignId('ejecucion_id')->nullable()->after('parametro_id')
                ->constrained('opm_ejecuciones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opm_detalles',  fn ($t) => $t->dropColumn('ejecucion_id'));
        Schema::table('opm_precios',   fn ($t) => $t->dropColumn('ejecucion_id'));
        Schema::table('opm_productos', fn ($t) => $t->dropColumn('ejecucion_id'));
        Schema::dropIfExists('opm_ejecuciones');
    }
};
