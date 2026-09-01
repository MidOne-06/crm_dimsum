<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('salidas_stock', fn (Blueprint $table) => $table->softDeletesTz());
        Schema::table('salida_stock_detalles', fn (Blueprint $table) => $table->softDeletesTz());
    }

    public function down(): void
    {
        Schema::table('salida_stock_detalles', fn (Blueprint $table) => $table->dropSoftDeletesTz());
        Schema::table('salidas_stock', fn (Blueprint $table) => $table->dropSoftDeletesTz());
    }
};
