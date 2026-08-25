<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Los "locales" (restaurantes) no viven en esta base de datos -- vienen en vivo
// del gateway (Restaurant.pe). Por eso local_id/local_nombre son texto plano
// (sin FK): local_nombre es un cache de visualización tomado del gateway al
// momento de asignar, no la fuente de verdad.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_locals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('local_id');
            $table->string('local_nombre')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_locals');
    }
};
