<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion_categorias', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre')->unique();
            $table->unsignedInteger('orden')->default(0)->index();
            $table->timestamps();
        });

        Schema::table('produccion_productos', function (Blueprint $table): void {
            $table->foreignId('produccion_categoria_id')->nullable()->after('id')->constrained('produccion_categorias')->nullOnDelete();
        });

        $categorias = [
            ['nombre' => 'Siu Mai', 'orden' => 1, 'prefijos' => ['SM']],
            ['nombre' => 'Min Pao', 'orden' => 2, 'prefijos' => ['MP']],
            ['nombre' => 'Enrollados y masas', 'orden' => 3, 'prefijos' => ['ER', 'WK', 'TP']],
            ['nombre' => 'Frituras y complementos', 'orden' => 4, 'prefijos' => ['AA', 'AB', 'KP', 'WT', 'SK', 'CS', 'CH']],
            ['nombre' => 'Salsas', 'orden' => 5, 'prefijos' => ['SA']],
        ];

        foreach ($categorias as $categoria) {
            DB::table('produccion_categorias')->updateOrInsert(
                ['nombre' => $categoria['nombre']],
                ['orden' => $categoria['orden'], 'updated_at' => now(), 'created_at' => now()],
            );

            $categoriaId = DB::table('produccion_categorias')->where('nombre', $categoria['nombre'])->value('id');
            DB::table('produccion_productos')->whereNull('produccion_categoria_id')->where(function ($query) use ($categoria): void {
                foreach ($categoria['prefijos'] as $prefijo) {
                    $query->orWhere('codigo', 'like', $prefijo.'%');
                }
            })->update(['produccion_categoria_id' => $categoriaId, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('produccion_productos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('produccion_categoria_id');
        });

        Schema::dropIfExists('produccion_categorias');
    }
};
