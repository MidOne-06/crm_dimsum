<?php

namespace App\Console\Commands;

use App\Models\VentaPayloadArchivo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MarcarRespaldosExternosVentas extends Command
{
    protected $signature = 'ventas:marcar-respaldo-externo
        {--disk= : Disco de staging que fue comparado contra el respaldo externo}
        {--origen= : Identificador del respaldo externo verificado}
        {--chunk=100 : Manifiestos por lote, entre 10 y 250}
        {--max=0 : Máximo de manifiestos a marcar; 0 procesa todos}
        {--recalcular-sha : Vuelve a descomprimir y calcular SHA-256 de cada archivo de staging}
        {--dry-run : Solo informa cuántos manifiestos son candidatos}
        {--confirmar : Confirmación explícita para registrar la evidencia externa}';

    protected $description = 'Marca manifiestos cuyos archivos locales fueron verificados contra un respaldo externo.';

    public function handle(): int
    {
        $disk = trim((string) $this->option('disk'));
        $origen = trim((string) $this->option('origen'));

        if ($disk === '' || $origen === '') {
            $this->error('Debe indicar --disk y --origen.');

            return self::FAILURE;
        }

        $consulta = VentaPayloadArchivo::query()
            ->where('disk', $disk)
            ->whereNotNull('verificado_en')
            ->whereNull('respaldo_externo_en');

        if ($this->option('dry-run')) {
            $this->info('Manifiestos candidatos: '.$consulta->count());

            return self::SUCCESS;
        }

        if (! $this->option('confirmar')) {
            $this->error('Para marcar evidencia externa debe incluir --confirmar.');

            return self::FAILURE;
        }

        $filesystem = Storage::disk($disk);
        $chunk = min(250, max(10, (int) $this->option('chunk')));
        $maximo = max(0, (int) $this->option('max'));
        $confirmadoEn = now();
        $recalcularSha = (bool) $this->option('recalcular-sha');
        $totales = ['marcados' => 0, 'presentes' => 0, 'sha_recalculados' => 0];

        try {
            $consulta->orderBy('id')->chunkById($chunk, function ($archivos) use ($filesystem, $origen, $confirmadoEn, $maximo, &$totales): bool {
                $idsMarcados = [];

                foreach ($archivos as $archivo) {
                    if ($maximo > 0 && ($totales['marcados'] + count($idsMarcados)) >= $maximo) {
                        break;
                    }

                    if (! $filesystem->exists($archivo->path)) {
                        throw new \RuntimeException("No existe el archivo de staging para la venta {$archivo->venta_id}.");
                    }

                    if ($recalcularSha) {
                        $contenido = $filesystem->get($archivo->path);
                        $json = is_string($contenido) ? gzdecode($contenido) : false;
                        if ($json === false || ! hash_equals($archivo->sha256, hash('sha256', $json))) {
                            throw new \RuntimeException("El SHA-256 del staging no coincide para la venta {$archivo->venta_id}.");
                        }

                        $totales['sha_recalculados']++;
                    }

                    $idsMarcados[] = $archivo->id;
                    $totales['presentes']++;
                }

                if ($idsMarcados !== []) {
                    VentaPayloadArchivo::query()
                        ->whereIn('id', $idsMarcados)
                        ->update([
                            'respaldo_externo_en' => $confirmadoEn,
                            'respaldo_externo_origen' => $origen,
                            'updated_at' => now(),
                        ]);

                    $totales['marcados'] += count($idsMarcados);
                }

                $this->output->write('.');

                return $maximo === 0 || $totales['marcados'] < $maximo;
            });
        } catch (\Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Manifiestos marcados: {$totales['marcados']} | staging presente: {$totales['presentes']} | SHA-256 recalculados: {$totales['sha_recalculados']}");

        return self::SUCCESS;
    }
}
