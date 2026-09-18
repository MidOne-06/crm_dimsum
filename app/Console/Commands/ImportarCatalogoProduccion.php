<?php

namespace App\Console\Commands;

use App\Models\ProduccionCategoria;
use App\Models\ProduccionProducto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportarCatalogoProduccion extends Command
{
    protected $signature = 'produccion:importar-catalogo
        {--payload= : Payload Base64 URL-safe generado desde Producción}
        {--purge : Elimina del entorno aislado productos y categorías que ya no existan en el origen}';

    protected $description = 'Importa únicamente el catálogo maestro de Producción en un entorno aislado.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'staging', 'testing']) || ! filter_var(env('ISOLATED_ENVIRONMENT', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Este comando solo puede ejecutarse en un entorno aislado.');

            return self::FAILURE;
        }

        $payload = $this->payload();
        if ($payload === null) {
            return self::FAILURE;
        }

        $categorias = $this->categoriasValidas($payload['categorias'] ?? null);
        $productos = $this->productosValidos($payload['productos'] ?? null, $categorias);

        if ($categorias === [] || $productos === []) {
            $this->error('El catálogo recibido no contiene categorías y productos válidos.');

            return self::FAILURE;
        }

        if ($this->option('purge') && $this->hayRegistrosOperativos()) {
            $this->error('No se puede depurar el catálogo: este entorno ya contiene registros operativos de Producción.');

            return self::FAILURE;
        }

        try {
            $resultado = DB::transaction(function () use ($categorias, $productos): array {
                $categoriaMap = [];
                $categoriaIds = [];

                foreach ($categorias as $categoria) {
                    $modelo = ProduccionCategoria::query()->firstOrNew(['nombre' => $categoria['nombre']]);
                    $modelo->orden = $categoria['orden'];
                    $modelo->save();

                    $categoriaMap[$categoria['origen_id']] = $modelo->id;
                    $categoriaIds[] = $modelo->id;
                }

                $creados = 0;
                $actualizados = 0;
                $productoIds = [];

                foreach ($productos as $producto) {
                    $modelo = ProduccionProducto::query()->firstOrNew([
                        'restaurant_item_id' => $producto['restaurant_item_id'],
                        'restaurant_item_tipo' => $producto['restaurant_item_tipo'],
                        'restaurant_presentacion_id' => $producto['restaurant_presentacion_id'],
                    ]);
                    $nuevo = ! $modelo->exists;

                    $modelo->fill([
                        'produccion_categoria_id' => $categoriaMap[$producto['categoria_origen_id']],
                        'codigo' => $producto['codigo'],
                        'nombre' => $producto['nombre'],
                        'unidad' => $producto['unidad'],
                        'activo' => $producto['activo'],
                    ]);
                    $modelo->save();

                    $nuevo ? $creados++ : $actualizados++;
                    $productoIds[] = $modelo->id;
                }

                $eliminados = 0;
                if ($this->option('purge')) {
                    $eliminados = ProduccionProducto::query()->whereNotIn('id', $productoIds)->delete();
                    ProduccionCategoria::query()->whereNotIn('id', $categoriaIds)->delete();
                }

                return compact('creados', 'actualizados', 'eliminados');
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se pudo importar el catálogo. No se aplicaron cambios parciales.');

            return self::FAILURE;
        }

        $this->info("Categorías: ".count($categorias)." | Productos creados: {$resultado['creados']} | Actualizados: {$resultado['actualizados']} | Eliminados: {$resultado['eliminados']}");

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function payload(): ?array
    {
        $encoded = (string) $this->option('payload');
        if ($encoded === '') {
            $this->error('Falta el payload de catálogo.');

            return null;
        }

        $encoded = strtr($encoded, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        $payload = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($payload)) {
            $this->error('El payload de catálogo no tiene un formato válido.');

            return null;
        }

        return $payload;
    }

    /** @param mixed $categorias @return array<int, array{origen_id:int,nombre:string,orden:int}> */
    private function categoriasValidas(mixed $categorias): array
    {
        if (! is_array($categorias)) {
            return [];
        }

        return collect($categorias)
            ->filter(fn ($categoria): bool => is_array($categoria)
                && filter_var($categoria['id'] ?? null, FILTER_VALIDATE_INT) !== false
                && filled($categoria['nombre'] ?? null))
            ->map(fn (array $categoria): array => [
                'origen_id' => (int) $categoria['id'],
                'nombre' => Str::of((string) $categoria['nombre'])->squish()->limit(255, '')->toString(),
                'orden' => max(0, (int) ($categoria['orden'] ?? 0)),
            ])
            ->unique('origen_id')
            ->values()
            ->all();
    }

    /**
     * @param mixed $productos
     * @param array<int, array{origen_id:int,nombre:string,orden:int}> $categorias
     * @return array<int, array<string, mixed>>
     */
    private function productosValidos(mixed $productos, array $categorias): array
    {
        if (! is_array($productos)) {
            return [];
        }

        $categoriaOrigenIds = collect($categorias)->pluck('origen_id')->all();

        return collect($productos)
            ->filter(fn ($producto): bool => is_array($producto)
                && in_array((int) ($producto['categoria_id'] ?? 0), $categoriaOrigenIds, true)
                && filled($producto['restaurant_item_id'] ?? null)
                && filled($producto['restaurant_item_tipo'] ?? null)
                && filled($producto['restaurant_presentacion_id'] ?? null)
                && filled($producto['nombre'] ?? null))
            ->map(fn (array $producto): array => [
                'categoria_origen_id' => (int) $producto['categoria_id'],
                'restaurant_item_id' => trim((string) $producto['restaurant_item_id']),
                'restaurant_item_tipo' => trim((string) $producto['restaurant_item_tipo']),
                'restaurant_presentacion_id' => trim((string) $producto['restaurant_presentacion_id']),
                'codigo' => filled($producto['codigo'] ?? null) ? trim((string) $producto['codigo']) : null,
                'nombre' => Str::of((string) $producto['nombre'])->squish()->limit(255, '')->toString(),
                'unidad' => Str::of((string) ($producto['unidad'] ?? 'UNIDAD'))->squish()->limit(255, '')->toString() ?: 'UNIDAD',
                'activo' => filter_var($producto['activo'] ?? true, FILTER_VALIDATE_BOOL),
            ])
            ->unique(fn (array $producto): string => implode('|', [
                $producto['restaurant_item_id'],
                $producto['restaurant_item_tipo'],
                $producto['restaurant_presentacion_id'],
            ]))
            ->values()
            ->all();
    }

    private function hayRegistrosOperativos(): bool
    {
        return DB::table('produccion_diaria_cierres')->exists()
            || DB::table('produccion_diaria_tandas')->exists()
            || DB::table('produccion_diaria_salidas')->exists()
            || DB::table('produccion_diaria_detalles')->exists()
            || DB::table('produccion_diaria_auditorias')->exists();
    }
}
