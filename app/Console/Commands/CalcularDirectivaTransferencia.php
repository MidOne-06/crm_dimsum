<?php

namespace App\Console\Commands;

use App\Services\DirectivaTransferenciaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CalcularDirectivaTransferencia extends Command
{
    protected $signature = 'directiva-transferencia:calcular {fecha? : Fecha de despacho a calcular (Y-m-d), por defecto hoy}';

    protected $description = 'Calcula la cantidad sugerida a despachar por local x ítem para la fecha indicada (por defecto hoy), en base al promedio histórico del mismo día de la semana y el saldo en tiempo real. Solo para locales activos (ver DirectivaTransferenciaService::localesActivos()).';

    public function handle(DirectivaTransferenciaService $service): int
    {
        $fecha = $this->argument('fecha') ?? now()->toDateString();
        Carbon::parse($fecha); // valida el formato, lanza si es inválido

        $total = $service->calcularParaFecha($fecha, soloVentaActiva: true);
        $this->info("Directiva de Transferencia calculada para {$fecha}: {$total} sugerencias (local x ítem).");

        return self::SUCCESS;
    }
}
