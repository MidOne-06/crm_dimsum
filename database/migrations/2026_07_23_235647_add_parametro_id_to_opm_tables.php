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
        Schema::table('opm_productos', function (Blueprint $table) {
            $table->unsignedBigInteger('parametro_id')->nullable()->after('id');
            $table->foreign('parametro_id')->references('id')->on('opm_parametros')->nullOnDelete();
        });

        Schema::table('opm_precios', function (Blueprint $table) {
            $table->unsignedBigInteger('parametro_id')->nullable()->after('id');
            $table->foreign('parametro_id')->references('id')->on('opm_parametros')->nullOnDelete();
        });

        Schema::table('opm_detalles', function (Blueprint $table) {
            $table->unsignedBigInteger('parametro_id')->nullable()->after('detail_key');
            $table->foreign('parametro_id')->references('id')->on('opm_parametros')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opm_productos', function (Blueprint $table) {
            $table->dropForeign(['parametro_id']);
            $table->dropColumn('parametro_id');
        });
        Schema::table('opm_precios', function (Blueprint $table) {
            $table->dropForeign(['parametro_id']);
            $table->dropColumn('parametro_id');
        });
        Schema::table('opm_detalles', function (Blueprint $table) {
            $table->dropForeign(['parametro_id']);
            $table->dropColumn('parametro_id');
        });
    }
};
