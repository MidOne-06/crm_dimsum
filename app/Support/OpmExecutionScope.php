<?php

namespace App\Support;

use App\Models\OpmEjecucion;
use Illuminate\Database\Eloquent\Builder;

class OpmExecutionScope
{
    /** @return array<int, string> */
    public static function options(?string $from = null, ?string $until = null): array
    {
        return OpmEjecucion::query()
            ->with('parametro')
            ->when($from, fn (Builder $query): Builder => $query->where('iniciado_at', '>=', $from))
            ->when($until, fn (Builder $query): Builder => $query->where('iniciado_at', '<=', $until))
            ->latest('iniciado_at')
            ->get()
            ->mapWithKeys(fn (OpmEjecucion $execution): array => [
                $execution->id => sprintf(
                    '%s · #%s · %s · %s',
                    $execution->parametro?->nombre ?? 'Sin parámetro',
                    $execution->id,
                    $execution->iniciado_at?->format('d/m/Y H:i') ?? 'Sin fecha',
                    ucfirst((string) $execution->estado),
                ),
            ])
            ->all();
    }

    /** @param array{ejecuciones?: array<int, int|string>, desde?: string|null, hasta?: string|null} $scope */
    public static function apply(Builder $query, array $scope): Builder
    {
        return $query
            ->when(
                filled($scope['ejecuciones'] ?? []),
                fn (Builder $query): Builder => $query->whereIn('ejecucion_id', $scope['ejecuciones']),
            )
            ->when(
                filled($scope['desde'] ?? null),
                fn (Builder $query): Builder => $query->whereHas(
                    'ejecucion',
                    fn (Builder $executionQuery): Builder => $executionQuery->where('iniciado_at', '>=', $scope['desde']),
                ),
            )
            ->when(
                filled($scope['hasta'] ?? null),
                fn (Builder $query): Builder => $query->whereHas(
                    'ejecucion',
                    fn (Builder $executionQuery): Builder => $executionQuery->where('iniciado_at', '<=', $scope['hasta']),
                ),
            );
    }

    /** @param array{ejecuciones?: array<int, int|string>, desde?: string|null, hasta?: string|null} $scope */
    public static function label(array $scope): ?string
    {
        $parts = [];

        $executionIds = array_values(array_unique(array_filter($scope['ejecuciones'] ?? [])));

        if (count($executionIds) === 1) {
            $parts[] = static::options()[$executionIds[0]] ?? '#'.$executionIds[0];
        } elseif ($executionIds !== []) {
            $parts[] = count($executionIds).' corridas';
        }

        if (filled($scope['desde'] ?? null) || filled($scope['hasta'] ?? null)) {
            $parts[] = trim(sprintf(
                '%s — %s',
                $scope['desde'] ?? 'Inicio',
                $scope['hasta'] ?? 'Actualidad',
            ));
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
