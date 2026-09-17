<?php

namespace App\Console\Commands;

use App\Models\Venta;
use App\Services\VentaPayloadArchivoService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ArchivarPayloadsVentas extends Command
{
    protected $signature = 'ventas:archivar-payloads
        {--disk= : Disco remoto configurado (por ejemplo, s3)}
        {--desde= : Fecha inicial inclusiva (YYYY-MM-DD)}
        {--hasta= : Fecha final inclusiva (YYYY-MM-DD)}
        {--chunk=100 : Ventas por lote, entre 10 y 250}
        {--dry-run : Solo informa cuántos payloads son candidatos}
        {--eliminar-raw : Elimina raw únicamente después de archivar y verificar}
        {--confirmar-eliminacion : Confirmación explícita requerida junto a --eliminar-raw}';

    protected $description = 'Archiva payloads de ventas en almacenamiento externo y los verifica por SHA-256.';

    public function handle(VentaPayloadArchivoService $archivos): int
    {
        $disk = trim((string) $this->option('disk'));
        if ($disk === '') {
            $this->error('Debe indicar un disco remoto con --disk.');

            return self::FAILURE;
        }

        if ($this->option('eliminar-raw') && ! $this->option('confirmar-eliminacion')) {
            $this->error('Para usar --eliminar-raw debe incluir también --confirmar-eliminacion.');

            return self::FAILURE;
        }

        if ($this->option('eliminar-raw') && $archivos->esDiscoLocal($disk)) {
            $this->error('No se permite eliminar raw cuando el archivo está en este mismo VPS. Use un disco externo verificado.');

            return self::FAILURE;
        }

        try {
            $desde = $this->option('desde') ? Carbon::parse((string) $this->option('desde'))->startOfDay() : null;
            $hasta = $this->option('hasta') ? Carbon::parse((string) $this->option('hasta'))->endOfDay() : null;
        } catch (\Throwable) {
            $this->error('Las fechas deben tener el formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($desde && $hasta && $hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $consulta = Venta::query()
            ->whereNotNull('raw')
            ->when($desde, fn (Builder $query): Builder => $query->where('venta_fecha', '>=', $desde))
            ->when($hasta, fn (Builder $query): Builder => $query->where('venta_fecha', '<=', $hasta));

        if ($this->option('dry-run')) {
            $this->info('Payloads candidatos: '.$consulta->count());

            return self::SUCCESS;
        }

        $chunk = min(250, max(10, (int) $this->option('chunk')));
        $totales = ['archivados' => 0, 'raw_eliminados' => 0];

        try {
            $consulta->orderBy('venta_fecha')->orderBy('venta_id')->chunk($chunk, function ($ventas) use ($archivos, $disk, &$totales): void {
                foreach ($ventas as $venta) {
                    $archivos->archivar($venta, $disk);
                    $totales['archivados']++;

                    if ($this->option('eliminar-raw')) {
                        $venta->forceFill(['raw' => null])->save();
                        $totales['raw_eliminados']++;
                    }
                }

                $this->output->write('.');
            });
        } catch (\Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Payloads archivados: {$totales['archivados']} | raw eliminados: {$totales['raw_eliminados']}");

        return self::SUCCESS;
    }
}
