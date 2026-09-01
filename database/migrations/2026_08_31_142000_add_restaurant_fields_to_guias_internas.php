<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guias_internas', function (Blueprint $table): void {
            $table->text('direccion_destino')->nullable()->after('local_destino');
            $table->jsonb('direccion_destino_payload')->nullable()->after('direccion_destino');
        });

        Schema::table('guia_interna_detalles', function (Blueprint $table): void {
            $table->decimal('cantidad_salida', 18, 4)->default(0)->after('cantidad');
            $table->string('stock')->nullable()->after('cantidad_salida');
            $table->decimal('peso', 18, 4)->default(0)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('guia_interna_detalles', function (Blueprint $table): void {
            $table->dropColumn(['cantidad_salida', 'stock', 'peso']);
        });

        Schema::table('guias_internas', function (Blueprint $table): void {
            $table->dropColumn(['direccion_destino', 'direccion_destino_payload']);
        });
    }
};
