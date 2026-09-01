<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('salidas_stock', function (Blueprint $table) { $table->id(); $table->string('restaurant_id')->unique(); $table->string('local_id')->nullable()->index(); $table->string('local_nombre')->nullable()->index(); $table->date('fecha')->nullable()->index(); $table->string('hora')->nullable(); $table->string('responsable')->nullable(); $table->string('categoria')->nullable()->index(); $table->decimal('importe',18,4)->default(0); $table->text('razon')->nullable(); $table->string('estado')->nullable()->index(); $table->jsonb('payload_restaurant')->nullable(); $table->timestampTz('sincronizado_en')->nullable()->index(); $table->timestampsTz(); });
  Schema::create('salida_stock_detalles', function (Blueprint $table) { $table->id(); $table->foreignId('salida_stock_id')->constrained('salidas_stock')->cascadeOnDelete(); $table->string('restaurant_id'); $table->string('item_id')->nullable()->index(); $table->string('item_codigo')->nullable()->index(); $table->string('item')->nullable(); $table->string('tipo')->nullable(); $table->string('almacen_id')->nullable()->index(); $table->string('almacen')->nullable(); $table->string('unidad')->nullable(); $table->decimal('cantidad',18,4)->default(0); $table->decimal('costo',18,4)->default(0); $table->decimal('total',18,4)->default(0); $table->jsonb('payload_restaurant')->nullable(); $table->timestampsTz(); $table->unique(['salida_stock_id','restaurant_id']); });
 }
 public function down(): void { Schema::dropIfExists('salida_stock_detalles'); Schema::dropIfExists('salidas_stock'); }
};
