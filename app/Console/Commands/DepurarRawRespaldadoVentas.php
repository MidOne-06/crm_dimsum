<?php

namespace App\Console\Commands;

use App\Models\Venta;
use App\Models\VentaPayloadArchivo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DepurarRawRespaldadoVentas extends Command
{
    protected $signature = 'ventas:depurar-raw-respaldado
        {--disk= : Disco de staging asociado al respaldo externo}
        {--chunk=100 : Manifiestos por lote, entre 10 y 250}
        {--max=0 : Máximo de payloads a depurar; 0 procesa todos}
        {--dry-run : Solo informa cuántos payloads cumplen todas las compuertas}
        {--eliminar-raw : Elimina raw solo después de validar su SHA-256}
        {--confirmar-eliminacion : Confirmación explícita requerida junto a --eliminar-raw}';

    protected $description = 'Elimina raw únicamente cuando existe respaldo externo marcado y el SHA-256 coincide.';

    public function handle(): int
    {
        $disk = trim((string) $this->option('disk'));
        if ($disk === '') {
            $this->error('Debe indicar --disk.');

            return self::FAILURE;
        }

        $consulta = VentaPayloadArchivo::query()
            ->where('disk', $disk)
            ->whereNotNull('verificado_en')
            ->whereNotNull('respaldo_externo_en')
            ->whereHas('venta', fn ($query) => $query->whereNotNull('raw'));

        if ($this->option('dry-run')) {
            $this->info('Payloads candidatos: '.$consulta->count());

            return self::SUCCESS;
        }

        if (! $this->option('eliminar-raw') || ! $this->option('confirmar-eliminacion')) {
            $this->error('La depuración requiere --eliminar-raw y --confirmar-eliminacion.');

            return self::FAILURE;
        }

        $chunk = min(250, max(10, (int) $this->option('chunk')));
        $maximo = max(0, (int) $this->option('max'));
        $totales = ['eliminados' => 0, 'omitidos' => 0];

        try {
            $consulta->orderBy('id')->chunkById($chunk, function ($archivos) use ($maximo, &$totales): bool {
                $pendientes = $maximo > 0
                    ? $archivos->take(max(0, $maximo - $totales['eliminados']))
                    : $archivos;

                if ($pendientes->isEmpty()) {
                    return false;
                }

                $resultado = DB::transaction(function () use ($pendientes): array {
                    $ventas = Venta::query()
                        ->whereIn('venta_id', $pendientes->pluck('venta_id'))
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('venta_id');
                    $idsEliminar = [];
                    $omitidos = 0;

                    foreach ($pendientes as $archivo) {
                        $venta = $ventas->get($archivo->venta_id);
                        if (! $venta || ! is_array($venta->raw)) {
                            $omitidos++;

                            continue;
                        }

                        $json = json_encode($venta->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                        if (! hash_equals($archivo->sha256, hash('sha256', $json))) {
                            throw new \RuntimeException("El SHA-256 de raw no coincide para la venta {$archivo->venta_id}.");
                        }

                        $idsEliminar[] = $venta->venta_id;
                    }

                    if ($idsEliminar !== []) {
                        Venta::query()
                            ->whereIn('venta_id', $idsEliminar)
                            ->whereNotNull('raw')
                            ->update(['raw' => null]);
                    }

                    return ['eliminados' => count($idsEliminar), 'omitidos' => $omitidos];
                });

                $totales['eliminados'] += $resultado['eliminados'];
                $totales['omitidos'] += $resultado['omitidos'];

                $this->output->write('.');

                return $maximo === 0 || $totales['eliminados'] < $maximo;
            });
        } catch (\Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Raw eliminados: {$totales['eliminados']} | omitidos: {$totales['omitidos']}");

        return self::SUCCESS;
    }
}
