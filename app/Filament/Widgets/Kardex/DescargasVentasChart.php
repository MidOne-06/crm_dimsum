<?php

namespace App\Filament\Widgets\Kardex;

use App\Filament\Concerns\ScopesLocalsToUser;
use App\Models\KardexMovimiento;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

abstract class DescargasVentasChart extends ChartWidget
{
    use ScopesLocalsToUser;

    protected const MOTIVO_VENTA = 'SALIDA, POR VENTA.';

    protected const ALMACEN_PRINCIPAL = 'Almacen Principal';

    /** @var array<string, mixed> */
    public array $analysisFilters = [];

    protected ?string $maxHeight = '300px';

    protected function baseQuery(): Builder
    {
        $selectedLocals = array_values(array_filter(
            $this->restrictLocalIdsToUser((array) ($this->analysisFilters['selectedLocals'] ?? [])),
            fn ($localId): bool => filled($localId),
        ));

        $start = $this->safeDate($this->analysisFilters['dateStart'] ?? null, now()->startOfMonth());
        $end = $this->safeDate($this->analysisFilters['dateEnd'] ?? null, now());
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $unidad = (string) ($this->analysisFilters['unidadMedida'] ?? 'UNIDAD');
        // Valor seguro, ya que el estado público de un widget puede alterarse.
        if (! in_array($unidad, ['UNIDAD', 'KILOS', 'LITRO'], true)) {
            $unidad = 'UNIDAD';
        }

        $categoria = trim((string) ($this->analysisFilters['categoria'] ?? ''));
        $producto = trim((string) ($this->analysisFilters['producto'] ?? ''));
        $selectedProducts = array_values(array_filter(
            (array) ($this->analysisFilters['selectedProducts'] ?? []),
            fn ($itemId): bool => filled($itemId),
        ));

        $query = KardexMovimiento::query()
            ->where('motivo', self::MOTIVO_VENTA)
            ->where('almacen', self::ALMACEN_PRINCIPAL)
            ->where('salida', '>', 0)
            ->where('unidad_medida', $unidad)
            ->whereBetween('fecha', [$start->toDateString(), $end->toDateString()])
            ->when(filled($selectedLocals), fn (Builder $query): Builder => $query->whereIn('local_id', $selectedLocals))
            ->when(filled($selectedProducts), fn (Builder $query): Builder => $query->whereIn('item_id', $selectedProducts))
            ->when($categoria !== '', fn (Builder $query): Builder => $query->where('categoria', $categoria));

        if (auth()->user()?->isRestrictedToLocals() && blank($selectedLocals)) {
            $query->whereIn('local_id', auth()->user()->assignedLocalIds());
        }

        if ($producto !== '') {
            $query->where(function (Builder $query) use ($producto): void {
                $query->where('item_nombre', 'ilike', "%{$producto}%")
                    ->orWhere('cod_interno', 'ilike', "%{$producto}%")
                    ->orWhere('producto', 'ilike', "%{$producto}%");
            });
        }

        return $query;
    }

    protected function unidad(): string
    {
        $unidad = (string) ($this->analysisFilters['unidadMedida'] ?? 'UNIDAD');

        return in_array($unidad, ['UNIDAD', 'KILOS', 'LITRO'], true) ? $unidad : 'UNIDAD';
    }

    protected function comparisonDimension(): string
    {
        return ($this->analysisFilters['comparisonDimension'] ?? 'producto') === 'dia'
            ? 'dia'
            : 'producto';
    }

    protected function rangoDescripcion(): string
    {
        $start = $this->safeDate($this->analysisFilters['dateStart'] ?? null, now()->startOfMonth());
        $end = $this->safeDate($this->analysisFilters['dateEnd'] ?? null, now());
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return sprintf('%s al %s · %s', $start->format('d/m/Y'), $end->format('d/m/Y'), $this->unidad());
    }

    protected function safeDate(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback->copy()->startOfDay();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return $fallback->copy()->startOfDay();
        }
    }
}
