<?php

namespace App\Filament\Concerns;

/**
 * Filtra una lista de locales (traída del gateway) a solo los asignados al
 * usuario autenticado, cuando corresponde. Un usuario sin locales asignados,
 * o superadministrador, ve la lista completa sin cambios.
 */
trait ScopesLocalsToUser
{
    /** @param array<int, array<string, mixed>> $locals */
    protected function scopeLocalsToUser(array $locals, string $idKey = 'id'): array
    {
        $user = auth()->user();

        if (! $user || ! $user->isRestrictedToLocals()) {
            return $locals;
        }

        $allowed = $user->assignedLocalIds();

        return array_values(array_filter(
            $locals,
            fn (array $local): bool => in_array((string) ($local[$idKey] ?? ''), $allowed, true),
        ));
    }

    /** Igual que scopeLocalsToUser() pero para un arreglo asociativo local_id => nombre. */
    protected function scopeKeyedLocalsToUser(array $keyedLocals): array
    {
        $user = auth()->user();

        if (! $user || ! $user->isRestrictedToLocals()) {
            return $keyedLocals;
        }

        $allowed = $user->assignedLocalIds();

        return array_filter($keyedLocals, fn ($_, $localId): bool => in_array((string) $localId, $allowed, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * scopeLocalsToUser()/scopeKeyedLocalsToUser() solo filtran qué opciones
     * se OFRECEN en un Select/CheckboxList -- no protegen nada por sí solas.
     * Estos dos métodos son los que hay que llamar en cada acción de
     * escritura/descarga real (guardar, exportar, iniciar extracción, etc.)
     * sobre el valor efectivamente recibido del formulario, porque local_id/
     * selectedLocals son propiedades públicas de Livewire y un usuario
     * restringido podría editar el payload wire:model y pedir un local fuera
     * de su alcance.
     */
    protected function localAllowedForUser(string $localId): bool
    {
        $user = auth()->user();

        if (! $user || ! $user->isRestrictedToLocals()) {
            return true;
        }

        return in_array($localId, $user->assignedLocalIds(), true);
    }

    /** @param array<int, string> $localIds @return array<int, string> */
    protected function restrictLocalIdsToUser(array $localIds): array
    {
        $user = auth()->user();

        if (! $user || ! $user->isRestrictedToLocals()) {
            return $localIds;
        }

        $allowed = $user->assignedLocalIds();

        return array_values(array_filter($localIds, fn ($id): bool => in_array((string) $id, $allowed, true)));
    }
}
