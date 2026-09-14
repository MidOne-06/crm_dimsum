<?php

namespace App\Console\Commands;

use App\Services\ProduccionCatalogoRestaurantService;
use Illuminate\Console\Command;

class SincronizarCatalogoProduccion extends Command
{
    protected $signature = 'produccion:sincronizar-catalogo';

    protected $description = 'Sincroniza los productos terminados de Fábrica desde Restaurant Logística.';

    public function handle(ProduccionCatalogoRestaurantService $catalogo): int
    {
        $resultado = $catalogo->sincronizarCatalogoInicial();

        $this->info("Creados: {$resultado['creados']} | Actualizados: {$resultado['actualizados']}");

        if ($resultado['faltantes'] !== []) {
            $this->warn('No encontrados: '.implode(', ', $resultado['faltantes']));
        }

        return self::SUCCESS;
    }
}
