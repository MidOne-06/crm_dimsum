<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion_productos', function (Blueprint $table): void {
            $table->string('restaurant_item_id', 80)->nullable()->after('id');
            $table->string('restaurant_item_tipo', 30)->nullable()->after('restaurant_item_id');
            $table->string('restaurant_presentacion_id', 80)->nullable()->after('restaurant_item_tipo');
            $table->dropUnique('produccion_productos_codigo_unique');
            $table->unique(['restaurant_item_id', 'restaurant_item_tipo', 'restaurant_presentacion_id'], 'produccion_productos_restaurant_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_productos', function (Blueprint $table): void {
            $table->dropUnique('produccion_productos_restaurant_item_unique');
            $table->unique('codigo');
            $table->dropColumn(['restaurant_item_id', 'restaurant_item_tipo', 'restaurant_presentacion_id']);
        });
    }
};
