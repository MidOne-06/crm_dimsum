<?php

namespace App\Console\Commands;

use App\Models\OpmParametro;
use App\Services\OpmProxyConfigurationService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class OpmEjecutarParametro extends Command
{
    protected $signature = 'opm:ejecutar {parametro : ID del parámetro}';
    protected $description = 'Ejecuta la extracción DIGEMID para un parámetro y lo importa a la BD';

    public function handle(): int
    {
        $id = (int) $this->argument('parametro');
        $parametro = OpmParametro::find($id);

        if (!$parametro) {
            $this->error("Parámetro #{$id} no encontrado.");
            return 1;
        }

        $this->info("Ejecutando parámetro #{$id}: {$parametro->nombre}");
        $this->line("Filtros: {$parametro->desc_categoria} / {$parametro->desc_tipo} / {$parametro->desc_departamento} / {$parametro->desc_provincia} / {$parametro->desc_distrito}");

        $script = base_path('scripts/opm-batch.mjs');
        $node   = $this->findNode();

        if (!$node) {
            $this->error('Node.js no encontrado. Asegúrate de tenerlo instalado.');
            return 1;
        }

        $proxy = app(OpmProxyConfigurationService::class)->values();

        $env = array_merge($_SERVER, [
            'PG_PASSWORD'          => config('database.connections.pgsql.password'),
            'DATAIMPULSE_HOST'     => $proxy['host'],
            'DATAIMPULSE_PORT'     => (string) $proxy['port'],
            'DATAIMPULSE_USER'     => $proxy['enabled'] ? $proxy['username'] : '',
            'DATAIMPULSE_PASSWORD' => $proxy['enabled'] ? $proxy['password'] : '',
            'OPM_BATCH_CONCURRENT' => env('OPM_BATCH_CONCURRENT', '50'),
            'OPM_DETAIL_CONCURRENT'=> env('OPM_DETAIL_CONCURRENT', '10'),
        ]);

        $process = new Process(
            [$node, $script, "--parametro={$id}"],
            base_path(),
            $env,
            null,
            null  // sin timeout (puede durar varios minutos)
        );

        $this->line('');
        $process->run(function (string $type, string $buffer) {
            // Stream output en tiempo real
            echo $buffer;
        });

        if (!$process->isSuccessful()) {
            $this->newLine();
            $this->error("El proceso terminó con error (código {$process->getExitCode()}).");
            OpmParametro::where('id', $id)->update(['estado' => 'error']);
            return 1;
        }

        $this->newLine();
        $parametro->refresh();
        $this->info("✅ Completado:");
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Estado',    $parametro->estado],
                ['Productos', number_format($parametro->total_productos)],
                ['Precios',   number_format($parametro->total_precios)],
                ['Detalles',  number_format($parametro->total_detalles)],
                ['Ejecutado', $parametro->ejecutado_at?->format('d/m/Y H:i')],
            ]
        );

        return 0;
    }

    private function findNode(): ?string
    {
        foreach (['node', 'C:\\Program Files\\nodejs\\node.exe', 'C:\\xampp\\nodejs\\node.exe'] as $candidate) {
            if (@is_executable($candidate)) return $candidate;
            $which = shell_exec(PHP_OS_FAMILY === 'Windows' ? "where {$candidate} 2>nul" : "which {$candidate} 2>/dev/null");
            if ($which) return trim($which);
        }
        return null;
    }
}
