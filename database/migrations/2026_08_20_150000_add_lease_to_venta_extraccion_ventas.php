<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('venta_extraccion_ventas', function (Blueprint $t) { $t->timestampTz('locked_at')->nullable()->index(); $t->unsignedSmallInteger('attempts')->default(0); }); } public function down(): void { Schema::table('venta_extraccion_ventas', fn (Blueprint $t) => $t->dropColumn(['locked_at','attempts'])); } };
