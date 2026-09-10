<?php

namespace App\Filament\Widgets\Entregas;

use App\Models\TransportistaAusencia;
use Saade\FilamentFullCalendar\Data\EventData;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

/**
 * Ausencias de transportistas en el calendario. Solo lectura -- sin
 * acciones de crear/editar acá (se hace desde el Resource
 * TransportistaAusenciaResource).
 */
class AusenciasCalendarWidget extends FullCalendarWidget
{
    public function fetchEvents(array $info): array
    {
        return TransportistaAusencia::query()
            ->with('transportista')
            ->where('fecha_inicio', '<=', $info['end'])
            ->where('fecha_fin', '>=', $info['start'])
            ->get()
            ->map(function (TransportistaAusencia $ausencia): array {
                $color = match ($ausencia->estado()) {
                    'vigente' => '#ef4444',
                    'proxima' => '#f59e0b',
                    default => '#9ca3af',
                };

                return EventData::make()
                    ->id((string) $ausencia->id)
                    ->title(($ausencia->transportista?->name ?? 'Transportista').' — '.$ausencia->motivo)
                    ->start($ausencia->fecha_inicio->toDateString())
                    // FullCalendar trata `end` como exclusivo en eventos de
                    // día completo, así que se suma 1 día para que pinte
                    // hasta la fecha_fin inclusive.
                    ->end($ausencia->fecha_fin->copy()->addDay()->toDateString())
                    ->allDay(true)
                    ->backgroundColor($color)
                    ->borderColor($color)
                    ->toArray();
            })
            ->all();
    }

    protected function headerActions(): array
    {
        return [];
    }

    protected function modalActions(): array
    {
        return [];
    }

    public function getFormSchema(): array
    {
        return [];
    }
}
