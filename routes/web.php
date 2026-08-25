<?php

use App\Http\Controllers\OpmCatalogTemplateController;
use App\Models\OpmEjecucion;
use App\Models\OpmParametro;
use App\Services\OpmBatchRunner;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.pages.dashboard');
});

Route::middleware(['web', 'auth', 'admin.access'])->prefix('admin/opm')->group(function () {

    Route::get('/catalogo/plantilla', OpmCatalogTemplateController::class)->name('opm.catalogo.plantilla');

    // Progreso de una ejecución específica
    Route::get('/ejecucion/{eid}/progress', function (int $eid) {
        $ej  = OpmEjecucion::findOrFail($eid)->fresh();
        $par = OpmParametro::findOrFail($ej->parametro_id)->fresh();
        $progress = OpmBatchRunner::readProgress($ej->parametro_id, $eid);
        $log      = OpmBatchRunner::readLog($ej->parametro_id, $eid, 40);

        return response()->json([
            'ejecucion' => [
                'id'              => $ej->id,
                'estado'          => $ej->estado,
                'total_productos' => $ej->total_productos,
                'total_precios'   => $ej->total_precios,
                'total_detalles'  => $ej->total_detalles,
                'iniciado_at'     => $ej->iniciado_at?->format('d/m/Y H:i:s'),
                'completado_at'   => $ej->completado_at?->format('d/m/Y H:i:s'),
            ],
            'parametro' => [
                'id'              => $par->id,
                'nombre'          => $par->nombre,
                'estado'          => $par->estado,
                'total_productos' => $par->total_productos,
                'total_precios'   => $par->total_precios,
                'total_detalles'  => $par->total_detalles,
            ],
            'progress' => $progress,
            'log'      => $log,
        ]);
    });

    // Compatibilidad con URL antigua (redirige a la ejecución más reciente)
    Route::get('/parametro/{id}/progress', function (int $id) {
        $parametro = OpmParametro::findOrFail($id);
        $ej = $parametro->ejecuciones()->first();
        if (!$ej) {
            return response()->json(['parametro' => ['id' => $id, 'estado' => $parametro->estado], 'progress' => null, 'log' => []]);
        }
        $ej        = $ej->fresh();
        $parametro = $parametro->fresh();
        $progress  = OpmBatchRunner::readProgress($id, $ej->id);
        $log       = OpmBatchRunner::readLog($id, $ej->id, 40);
        return response()->json([
            'ejecucion' => ['id' => $ej->id, 'estado' => $ej->estado],
            'parametro' => [
                'id'              => $id,
                'nombre'          => $parametro->nombre,
                'estado'          => $parametro->estado,
                'total_productos' => $parametro->total_productos,
                'total_precios'   => $parametro->total_precios,
                'total_detalles'  => $parametro->total_detalles,
            ],
            'progress' => $progress,
            'log'      => $log,
        ]);
    });
});
