<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuotas_ventas_restaurant', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 32);
            $table->string('local_id', 32)->nullable();
            $table->string('local');
            $table->date('periodo');
            $table->decimal('cuota_sin_igv', 14, 2)->default(0);
            $table->decimal('cuota_con_igv', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['codigo', 'periodo']);
            $table->index(['local_id', 'periodo']);
        });

        $periodo = '2026-09-01';
        $now = now();
        $cuotas = [
            ['TSM001', '6', 'DIM SUM METRO GARZON', 27000, 29835],
            ['TSW001', '7', 'DIM SUM WONG SAN MIGUEL', 21000, 23205],
            ['TSW002', '8', 'DIM SUM WONG GARDENIAS', 31000, 34255],
            ['TSW003', '9', 'DIM SUM WONG CHACARILLA', 40000, 44200],
            ['TSW004', '11', 'DIM SUM WONG DOS DE MAYO', 33000, 36465],
            ['TSW005', '12', 'DIM SUM WONG OVALO GUTIERREZ', 42000, 46410],
            ['TSW006', '13', 'DIM SUM WONG BENAVIDES', 22000, 24310],
            ['TSW007', '14', 'DIM SUM WONG UCELLO', 27000, 29835],
            ['TSM003', '15', 'DIM SUM MET LIMATAMBO 1', 12000, 13260],
            ['TSW008', '3', 'DIM SUM WONG VIÑAS', 20000, 22100],
            ['TCC001', '16', 'DIM SUM PLAZA SAN MIGUEL', 50000, 55250],
            ['TSW009', '17', 'DIM SUM WONG ATE', 30000, 33150],
            ['TSM004', '4', 'DIM SUM METRO SAN JUAN', 28000, 30940],
            ['TPC001', '18', 'DIM SUM VILLARAN', 35000, 38675],
            ['TSM005', '19', 'DIM SUM METRO LA MARINA', 40000, 44200],
            ['TCC002', '20', 'DIM SUM MINKA', 24000, 26520],
            ['TCC003', '21', 'DIM SUM PLAZA NORTE', 63650, 70333.25],
            ['TSW010', '23', 'DIM SUM WONG AURORA', 14500, 16022.50],
            ['TSM006', '32', 'DIM SUM MET LIMATAMBO 2', 21000, 23205],
            ['TCC006', '26', 'DIM SUM MALL SJL', 25000, 27625],
            ['TTR001', '27', 'DIM SUM EST CULTURA', 58000, 64090],
            ['TTR002', '29', 'DIM SUM EST ANGAMOS', 22000, 24310],
            ['TTR003', '28', 'DIM SUM EST GAMARRA', 40000, 44200],
            ['TCC010', '2', 'DIM SUM KM 40', 0, 0],
            ['TTR005', '31', 'DIM SUM EST JARDINES', 14000, 15470],
            ['TTR004', '30', 'DIM SUM EST GRAU', 21000, 23205],
            ['TCC012', '22', 'DIM SUM HIGUERETA', 22000, 24310],
            ['TCC009', null, 'DIM SUM PUNTAMAR', 0, 0],
            ['FRA001', '38', 'DIM SUM PERSHING', 16000, 17680],
            ['FRA002', null, 'DIM SUM ARENALES', 10000, 11050],
        ];

        foreach ($cuotas as [$codigo, $localId, $local, $sinIgv, $conIgv]) {
            DB::table('cuotas_ventas_restaurant')->updateOrInsert(
                ['codigo' => $codigo, 'periodo' => $periodo],
                [
                    'local_id' => $localId,
                    'local' => $local,
                    'cuota_sin_igv' => $sinIgv,
                    'cuota_con_igv' => $conIgv,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cuotas_ventas_restaurant');
    }
};
