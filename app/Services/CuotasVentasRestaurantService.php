<?php

namespace App\Services;

use App\Models\CuotaVentaRestaurant;
use App\Models\CuotaVentaRestaurantAuditoria;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CuotasVentasRestaurantService
{
    /** @param array{codigo:string,local_id:?string,local:string,cuota_sin_igv:numeric,cuota_con_igv:numeric} $datos */
    public function guardar(array $datos, Carbon|string $periodo, ?User $usuario, string $accion = 'actualizada'): CuotaVentaRestaurant
    {
        $mes = Carbon::parse($periodo)->startOfMonth()->toDateString();

        return DB::transaction(function () use ($datos, $mes, $usuario, $accion): CuotaVentaRestaurant {
            $cuota = CuotaVentaRestaurant::query()
                ->where('codigo', $datos['codigo'])
                ->whereDate('periodo', $mes)
                ->lockForUpdate()
                ->firstOrNew();
            $antes = $cuota->exists ? $this->snapshot($cuota) : null;

            $cuota->fill([
                'codigo' => trim($datos['codigo']),
                'local_id' => filled($datos['local_id'] ?? null) ? (string) $datos['local_id'] : null,
                'local' => trim($datos['local']),
                'periodo' => $mes,
                'cuota_sin_igv' => round((float) $datos['cuota_sin_igv'], 2),
                'cuota_con_igv' => round((float) $datos['cuota_con_igv'], 2),
            ]);
            $cuota->save();

            CuotaVentaRestaurantAuditoria::query()->create([
                'cuota_venta_restaurant_id' => $cuota->id,
                'accion' => $antes === null ? 'creada' : $accion,
                'antes' => $antes,
                'despues' => $this->snapshot($cuota->fresh()),
                'usuario_id' => $usuario?->id,
            ]);

            return $cuota;
        });
    }

    public function copiarMes(Carbon|string $origen, Carbon|string $destino, bool $sobrescribir, ?User $usuario): int
    {
        $origen = Carbon::parse($origen)->startOfMonth();
        $destino = Carbon::parse($destino)->startOfMonth();
        if ($origen->isSameMonth($destino)) {
            throw new \InvalidArgumentException('El mes de origen y destino deben ser distintos.');
        }

        $filas = CuotaVentaRestaurant::query()->whereDate('periodo', $origen->toDateString())->orderBy('codigo')->get();
        foreach ($filas as $fila) {
            $existe = CuotaVentaRestaurant::query()->where('codigo', $fila->codigo)->whereDate('periodo', $destino->toDateString())->exists();
            if ($existe && ! $sobrescribir) {
                continue;
            }
            $this->guardar([
                'codigo' => $fila->codigo,
                'local_id' => $fila->local_id,
                'local' => $fila->local,
                'cuota_sin_igv' => $fila->cuota_sin_igv,
                'cuota_con_igv' => $fila->cuota_con_igv,
            ], $destino, $usuario, 'copiada');
        }

        return $filas->count();
    }

    public function importarExcel(string $archivo, Carbon|string $periodo, ?User $usuario): int
    {
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray(null, true, true, false);
        $cabecera = $this->ubicarCabecera($filas);
        $catalogo = CuotaVentaRestaurant::query()->orderByDesc('periodo')->get()->unique('codigo')->keyBy('codigo');
        $registros = [];

        foreach (array_slice($filas, $cabecera['fila'] + 1) as $indice => $fila) {
            $codigo = trim((string) ($fila[$cabecera['codigo']] ?? ''));
            if ($codigo === '') {
                continue;
            }
            if (isset($registros[$codigo])) {
                throw new \InvalidArgumentException('El Excel repite el código '.$codigo.'.');
            }
            $referencia = $catalogo->get($codigo);
            $local = trim((string) ($fila[$cabecera['local']] ?? $referencia?->local ?? ''));
            if ($local === '') {
                throw new \InvalidArgumentException('Falta la tienda del código '.$codigo.'.');
            }
            $registros[$codigo] = [
                'codigo' => $codigo,
                'local_id' => $referencia?->local_id,
                'local' => $local,
                'cuota_sin_igv' => $this->numero($fila[$cabecera['sin_igv']] ?? null, $codigo, 'sin IGV'),
                'cuota_con_igv' => $this->numero($fila[$cabecera['con_igv']] ?? null, $codigo, 'con IGV'),
            ];
        }
        if ($registros === []) {
            throw new \InvalidArgumentException('El Excel no contiene cuotas válidas.');
        }

        DB::transaction(function () use ($registros, $periodo, $usuario): void {
            foreach ($registros as $registro) {
                $this->guardar($registro, $periodo, $usuario, 'importada');
            }
        });

        return count($registros);
    }

    /** @param array<int, array<int, mixed>> $filas @return array{fila:int,codigo:int,local:int,sin_igv:int,con_igv:int} */
    private function ubicarCabecera(array $filas): array
    {
        foreach (array_slice($filas, 0, 15) as $filaIndice => $fila) {
            $campos = [];
            foreach ($fila as $columna => $valor) {
                $normalizado = $this->normalizarCabecera((string) $valor);
                if (in_array($normalizado, ['CODIGO', 'COD'], true)) $campos['codigo'] = $columna;
                if (in_array($normalizado, ['TIENDA', 'LOCAL'], true)) $campos['local'] = $columna;
                if ($normalizado === 'CUOTA SIN IGV') $campos['sin_igv'] = $columna;
                if ($normalizado === 'CUOTA CON IGV') $campos['con_igv'] = $columna;
            }
            if (isset($campos['codigo'], $campos['local'], $campos['sin_igv'], $campos['con_igv'])) {
                return ['fila' => $filaIndice, ...$campos];
            }
        }
        throw new \InvalidArgumentException('El Excel debe incluir: CÓDIGO, TIENDA, CUOTA SIN IGV y CUOTA CON IGV.');
    }

    private function numero(mixed $valor, string $codigo, string $campo): float
    {
        if (is_numeric($valor)) return (float) $valor;
        $texto = str_replace(['S/', ' ', "\u{00A0}"], '', trim((string) $valor));
        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            $texto = strrpos($texto, ',') > strrpos($texto, '.')
                ? str_replace(',', '.', str_replace('.', '', $texto))
                : str_replace(',', '', $texto);
        }
        elseif (str_contains($texto, ',')) $texto = str_replace(',', '.', $texto);
        if (! is_numeric($texto)) throw new \InvalidArgumentException("La cuota {$campo} del código {$codigo} no es numérica.");
        return (float) $texto;
    }

    private function normalizarCabecera(string $valor): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtoupper(\Illuminate\Support\Str::ascii($valor))));
    }

    /** @return array<string, mixed> */
    private function snapshot(CuotaVentaRestaurant $cuota): array
    {
        return $cuota->only(['codigo', 'local_id', 'local', 'periodo', 'cuota_sin_igv', 'cuota_con_igv']);
    }
}
