<?php

namespace App\Console\Commands;

use App\Services\OpmCatalogSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class OpmSincronizarCatalogo extends Command
{
    protected $signature = 'opm:catalogo:sincronizar';
    protected $description = 'Descarga y valida el catálogo diario oficial de DIGEMID';

    public function handle(OpmCatalogSynchronizer $synchronizer): int
    {
        try {
            $result = $synchronizer->synchronize();
            $catalogo = $result['catalogo'];

            $this->info($result['changed'] ? 'Catálogo actualizado.' : 'Catálogo verificado sin cambios.');
            $this->table(['Catálogo', 'Valor'], [
                ['Hash', substr($catalogo->sha256, 0, 16)],
                ['Filas', number_format($catalogo->total_filas)],
                ['Nombres únicos', number_format($catalogo->total_nombres_unicos)],
                ['Combinaciones', number_format($catalogo->total_combinaciones_unicas)],
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se actualizó el catálogo: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
