<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Auditoría de seguridad (2026-09-16): la contraseña de un usuario Terminal
// se generaba de forma 100% determinística (nombre del local + un sufijo
// fijo compartido por TODAS las terminales), así que cualquiera que
// conociera el nombre del local podía calcularla sin necesidad de "verla" en
// el modal, y "Restablecer" recalculaba el mismo valor -- no rotaba nada
// ante una sospecha de fuga. El reemplazo genera una contraseña aleatoria
// real en cada reset; como es una credencial de un dispositivo físico
// compartido (no de una persona), y el propio modal de UserResource necesita
// poder MOSTRARLA para que alguien la tipee en la terminal, se guarda en
// texto plano acá -- a diferencia de `password` (hasheada), esta columna
// existe justamente para poder leerse de vuelta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('terminal_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('terminal_password');
        });
    }
};
