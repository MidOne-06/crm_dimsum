<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('requerimientos_stock_historicos', function (Blueprint $table): void {
            $table->text('ultima_sincronizacion_error')->nullable()->after('sincronizado_en');
        });
        Schema::table('requerimientos_stock_historicos_detalles', function (Blueprint $table): void {
            $table->boolean('activo')->default(true)->index()->after('observacion');
            $table->timestamp('eliminado_en')->nullable()->after('activo');
        });
        Schema::create('requerimientos_stock_historico_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requerimiento_stock_historico_id')->constrained('requerimientos_stock_historicos')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->json('antes')->nullable();
            $table->json('despues');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('requerimientos_stock_historico_eventos');
        Schema::table('requerimientos_stock_historicos_detalles', function (Blueprint $table): void { $table->dropColumn(['activo', 'eliminado_en']); });
        Schema::table('requerimientos_stock_historicos', function (Blueprint $table): void { $table->dropColumn('ultima_sincronizacion_error'); });
    }
};
