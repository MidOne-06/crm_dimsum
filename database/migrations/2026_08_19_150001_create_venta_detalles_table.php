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
        if (Schema::hasTable('venta_detalles')) {
            return;
        }

        Schema::create('venta_detalles', function (Blueprint $table) {
            $table->id();
            $table->string('venta_id');
            $table->string('item_id')->nullable();
            $table->string('descripcion')->nullable();
            $table->decimal('cantidad', 14, 4)->default(0);
            $table->decimal('precio', 12, 4)->default(0);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('importe', 12, 2)->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('venta_id')->references('venta_id')->on('ventas')->cascadeOnDelete();
            $table->index('venta_id');
            $table->unique(['venta_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_detalles');
    }
};
