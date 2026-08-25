<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Lee el índice generado desde el catálogo, nunca el Excel en tiempo real.
 * Así el selector y el lote usan exactamente la misma fuente de nombres.
 */
final class OpmCatalogProductLookup
{
    /** @return Collection<string, string> clave normalizada => etiqueta del catálogo */
    public function products(): Collection
    {
        $path = (string) env('OPM_CATALOG_INDEX', '');
        $cacheKey = 'opm-catalog-unique-products-'.sha1($path.'|'.(is_file($path) ? filemtime($path) : 'missing'));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($path): Collection {

            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('No se puede leer el índice del catálogo OPM configurado.');
            }

            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

            return collect($rows)
                ->map(fn (array $row): string => trim((string) ($row['Nom_Prod'] ?? '')))
                ->filter()
                ->mapWithKeys(fn (string $name): array => [OpmDigemidLiveValidator::normalizeText($name) => $name])
                ->sort();
        });
    }

    /** @return array<string, string> */
    public function search(string $search, int $limit = 50): array
    {
        $needle = OpmDigemidLiveValidator::normalizeText($search);

        return $this->products()
            ->filter(fn (string $label, string $key): bool => str_contains($key, $needle))
            ->take($limit)
            ->all();
    }

    public function label(string $normalizedName): ?string
    {
        return $this->products()->get(OpmDigemidLiveValidator::normalizeText($normalizedName));
    }
}
