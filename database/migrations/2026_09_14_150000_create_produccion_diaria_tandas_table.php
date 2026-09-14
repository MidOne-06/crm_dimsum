<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion_diaria_tandas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cierre_id')->constrained('produccion_diaria_cierres')->cascadeOnDelete();
            $table->string('item_id');
            $table->string('item_tipo')->nullable();
            $table->string('item_codigo')->nullable();
            $table->string('item_nombre');
            $table->string('unidad')->nullable();
            $table->decimal('cantidad', 14, 4);
            $table->text('nota')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['cierre_id', 'item_id'], 'produccion_tandas_cierre_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_diaria_tandas');
    }
};
