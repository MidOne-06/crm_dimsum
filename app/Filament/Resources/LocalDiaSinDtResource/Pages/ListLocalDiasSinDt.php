<?php

namespace App\Filament\Resources\LocalDiaSinDtResource\Pages;

use App\Filament\Resources\LocalDiaSinDtResource;
use App\Models\LocalDiaSinDt;
use App\Models\StockInicialLocal;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLocalDiasSinDt extends ListRecords
{
    protected static string $resource = LocalDiaSinDtResource::class;

    /**
     * El modal admite "todos los locales" o un multi-select -- el modelo
     * sigue siendo una fila por (local, día), así que acá se crea una por
     * cada local elegido en vez de dejar que `CreateAction` intente guardar
     * un solo registro con un array en `local_id`. `firstOrCreate()` por
     * combinación -- si alguna ya existía, se cuenta aparte y se avisa, no
     * se trata como error (pedido explícito: "sin errores").
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva excepción')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Guardar')
                ->modalCancelActionLabel('Cancelar')
                ->using(function (array $data): LocalDiaSinDt {
                    $localesIds = $data['todos_los_locales'] ?? false
                        ? StockInicialLocal::where('estado', 'confirmado')->pluck('local_id')->all()
                        : (array) ($data['locales'] ?? []);

                    $creadas = 0;
                    $yaExistian = 0;
                    $ultima = null;
                    foreach ($localesIds as $localId) {
                        $excepcion = LocalDiaSinDt::firstOrCreate([
                            'local_id' => (string) $localId,
                            'dia_semana' => (int) $data['dia_semana'],
                        ]);
                        $excepcion->wasRecentlyCreated ? $creadas++ : $yaExistian++;
                        $ultima = $excepcion;
                    }

                    $diaNombre = LocalDiaSinDt::DIAS[(int) $data['dia_semana']] ?? $data['dia_semana'];
                    $cuerpo = "{$creadas} excepción(es) nueva(s) para el {$diaNombre}.";
                    if ($yaExistian > 0) {
                        $cuerpo .= " {$yaExistian} ya existían y se dejaron igual.";
                    }
                    Notification::make()->success()->title('Días sin DT registrados')->body($cuerpo)->send();

                    // CreateAction necesita devolver un Model -- se usa la
                    // última fila tocada (creada o ya existente) como
                    // referencia; ninguna otra parte del flujo depende de
                    // cuál sea exactamente.
                    return $ultima ?? new LocalDiaSinDt();
                }),
        ];
    }
}
