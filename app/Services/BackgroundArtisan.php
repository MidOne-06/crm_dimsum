<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Lanza un comando artisan que sobrevive de verdad a que el proceso que lo
 * lanzó termine.
 *
 * Por qué existe: `Illuminate\Support\Facades\Process::start()` (Symfony
 * Process vía proc_open) NO sobrevive en este contenedor -- se comprobó
 * empíricamente con un `sleep 30` de prueba, tanto ejecutado dentro del
 * worker web como directo por consola dentro del contenedor de producción:
 * el hijo muere en cuanto el proceso PHP que lo creó termina.
 *
 * El propio scheduler de Laravel NO usa Process::start() para sus tareas en
 * segundo plano (`->runInBackground()`) -- construye una línea de shell
 * terminada en `&` real (ver Illuminate\Console\Scheduling\CommandBuilder)
 * y la corre con Process::fromShellCommandline(...)->run(). Ese patrón SÍ
 * sobrevive (comprobado con el mismo `sleep 30`: el hijo quedó corriendo y
 * terminó correctamente varios segundos después de que el proceso padre
 * saliera). Esta clase replica exactamente ese mecanismo para el resto del
 * código (extracciones:despachar-pendientes, extracciones:reanudar-huerfanas,
 * y cualquier otro lugar que necesite lanzar un artisan y no esperarlo).
 */
class BackgroundArtisan
{
    /**
     * @param  array<int, string>  $args  Argumentos de artisan SIN el "php artisan" inicial, ej. ['guias-internas:sincronizar', '--sync-id=23'].
     */
    public static function start(array $args): void
    {
        $php = escapeshellarg((string) (PHP_BINARY ?: 'php'));
        $artisan = escapeshellarg(base_path('artisan'));
        $partes = array_map(fn (string $arg): string => escapeshellarg($arg), $args);
        $comando = trim($php.' '.$artisan.' '.implode(' ', $partes));

        if (windows_os()) {
            // Igual que CommandBuilder::buildBackgroundCommand en Windows: start /b desprende de verdad.
            Process::fromShellCommandline('start /b cmd /v:on /c "'.$comando.' > NUL 2>&1"', base_path())->run();

            return;
        }

        // No enviar stderr a /dev/null: una extracción que muere sin dejar
        // traza no se puede diagnosticar ni reanudar con seguridad. El log
        // persiste en el volumen storage y es el mismo canal operativo que
        // se consulta desde el contenedor cuando una corrida falla.
        $log = escapeshellarg(storage_path('logs/background-artisan.log'));
        Process::fromShellCommandline('('.$comando.') >> '.$log.' 2>&1 &', base_path())->run();
    }
}
