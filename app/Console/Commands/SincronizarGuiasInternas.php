<?php
namespace App\Console\Commands;
use App\Services\GuiasInternasGatewayClient; use App\Services\GuiasInternasHistoricoService; use Illuminate\Console\Command; use Illuminate\Support\Facades\Cache;
class SincronizarGuiasInternas extends Command { protected $signature='guias-internas:sincronizar {--desde=2025-12-01} {--hasta=} {--locales=*} {--estado=-1} {--filtro-fecha=1} {--iniciado-por=} {--sync-id=}'; protected $description='Sincroniza y reconcilia las Guías internas de Restaurant en la copia local.'; public function handle(GuiasInternasHistoricoService $service,GuiasInternasGatewayClient $gateway):int{$lock=Cache::lock('guias-internas:sync',14400);if(!$lock->get()){$this->info('Ya hay una sincronización de Guías internas en curso.');return self::SUCCESS;}try{
    $locales=array_values(array_filter((array)$this->option('locales')));
    $estado=(string)$this->option('estado');
    $filtroFecha=in_array((string)$this->option('filtro-fecha'),['0','1'],true)?(string)$this->option('filtro-fecha'):'1';
    // OJO, bug real encontrado en producción (2026-09-11): sin lista explícita de
    // locales, el gateway NO sincroniza "todos" -- cae al local de la propia sesión
    // de login de Restaurant (uno solo, no la cadena real), así que esta sincronización
    // automática llevaba tiempo sin traer guías nuevas para casi ningún local. Si no
    // vino una lista por CLI, se pide la real y vigente a Restaurant antes de iniciar
    // (para que quede guardada en $sync->filtros y sea la que de verdad se usa).
    if ($locales === []) {
        $locales = array_values(array_map('strval', array_column($gateway->locales(), 'id')));
    }
    $sync=filled($this->option('sync-id'))?\App\Models\GuiaInternaSincronizacion::query()->findOrFail((int)$this->option('sync-id')):$service->iniciar((string)$this->option('desde'),(string)($this->option('hasta')?:now()->toDateString()),$locales,filled($this->option('iniciado-por'))?(int)$this->option('iniciado-por'):null);
    if (filled($this->option('sync-id'))) {
        // Al resumir una corrida ya existente, usar SU lista guardada -- si es de
        // antes de este fix y quedó vacía, mismo respaldo que arriba.
        $localesGuardados = array_values(array_filter((array) ($sync->filtros['locales'] ?? [])));
        $locales = $localesGuardados !== [] ? $localesGuardados : $locales;
    }
    $sync->update(['proceso_pid'=>getmypid()]);
    $this->info(json_encode($service->sincronizar($sync,$gateway,$locales,$estado,$filtroFecha)));
    return self::SUCCESS;
}catch(\Throwable $e){if(isset($sync))$sync->update(['estado'=>'fallido','mensaje_error'=>$e->getMessage(),'completado_en'=>now()]);throw $e;}finally{$lock->release();}} }
