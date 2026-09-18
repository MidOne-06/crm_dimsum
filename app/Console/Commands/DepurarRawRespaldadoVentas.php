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
                foreach ($archivos as $archivo) {
                    if ($maximo > 0 && $totales['eliminados'] >= $maximo) {
                        return false;
                    }

                    DB::transaction(function () use ($archivo, &$totales): void {
                        $venta = Venta::query()->whereKey($archivo->venta_id)->lockForUpdate()->first();
                        if (! $venta || ! is_array($venta->raw)) {
                            $totales['omitidos']++;

                            return;
                        }

                        $json = json_encode($venta->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                        if (! hash_equals($archivo->sha256, hash('sha256', $json))) {
                            throw new \RuntimeException("El SHA-256 de raw no coincide para la venta {$archivo->venta_id}.");
                        }

                        $venta->forceFill(['raw' => null])->save();
                        $totales['eliminados']++;
                    });
                }

                $this->output->write('.');

                return true;
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
