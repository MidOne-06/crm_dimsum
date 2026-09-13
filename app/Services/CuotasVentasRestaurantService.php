<?php

namespace App\Services;

use App\Models\CuotaVentaRestaurant;
use App\Models\CuotaVentaRestaurantAuditoria;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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

    /**
     * Analiza un archivo de cuotas sin persistir cambios.
     *
     * @return array{total:int,nuevas:int,actualizaciones:int,errores:array<int,string>,registros:array<string,array<string,mixed>>}
     */
    public function prevalidarExcel(string $archivo, Carbon|string $periodo, bool $sobrescribir = false): array
    {
        $mes = Carbon::parse($periodo)->startOfMonth()->toDateString();
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray(null, true, true, false);
        $errores = [];

        try {
            $cabecera = $this->ubicarCabecera($filas);
        } catch (\InvalidArgumentException $exception) {
            return [
                'total' => 0,
                'nuevas' => 0,
                'actualizaciones' => 0,
                'errores' => [$exception->getMessage()],
                'registros' => [],
            ];
        }

        $catalogo = $this->catalogoPorCodigo();
        $registros = [];

        foreach (array_slice($filas, $cabecera['fila'] + 1) as $indice => $fila) {
            $numeroFila = $cabecera['fila'] + $indice + 2;
            $codigo = $this->normalizarCodigo($fila[$cabecera['codigo']] ?? '');
            $tieneContenido = collect($fila)->contains(fn (mixed $valor): bool => filled($valor));
            if ($codigo === '') {
                if ($tieneContenido) {
                    $errores[] = "Fila {$numeroFila}: falta el código.";
                }
                continue;
            }
            if (isset($registros[$codigo])) {
                $errores[] = "Fila {$numeroFila}: el código {$codigo} está repetido.";
                continue;
            }
            $referencia = $catalogo->get($codigo);
            if ($referencia === null) {
                $errores[] = "Fila {$numeroFila}: el código {$codigo} no pertenece a un local Restaurant.";
                continue;
            }

            $local = trim((string) ($fila[$cabecera['local']] ?? ''));
            if ($local === '') {
                $errores[] = "Fila {$numeroFila}: falta la tienda del código {$codigo}.";
                continue;
            }
            if ($this->normalizarLocal($local) !== $this->normalizarLocal($referencia->local)) {
                $errores[] = "Fila {$numeroFila}: la tienda no corresponde al código {$codigo}.";
                continue;
            }

            try {
                $registros[$codigo] = [
                    'codigo' => $codigo,
                    'local_id' => $referencia->local_id,
                    'local' => $referencia->local,
                    'cuota_sin_igv' => $this->numero($fila[$cabecera['sin_igv']] ?? null, $codigo, 'sin IGV'),
                    'cuota_con_igv' => $this->numero($fila[$cabecera['con_igv']] ?? null, $codigo, 'con IGV'),
                ];
            } catch (\InvalidArgumentException $exception) {
                $errores[] = "Fila {$numeroFila}: {$exception->getMessage()}";
            }
        }

        if ($registros === []) {
            $errores[] = 'El Excel no contiene cuotas válidas.';
        }

        $existentes = CuotaVentaRestaurant::query()
            ->whereDate('periodo', $mes)
            ->whereIn('codigo', array_keys($registros))
            ->pluck('id', 'codigo');
        $actualizaciones = $existentes->count();
        if (($actualizaciones > 0) && ! $sobrescribir) {
            $errores[] = "Hay {$actualizaciones} cuotas existentes para este mes. Activa “Reemplazar cuotas existentes” para actualizarlas.";
        }

        return [
            'total' => count($registros),
            'nuevas' => count($registros) - $actualizaciones,
            'actualizaciones' => $actualizaciones,
            'errores' => $errores,
            'registros' => $registros,
        ];
    }

    public function importarExcel(string $archivo, Carbon|string $periodo, ?User $usuario, bool $sobrescribir = false): int
    {
        $prevalidacion = $this->prevalidarExcel($archivo, $periodo, $sobrescribir);
        if ($prevalidacion['errores'] !== []) {
            throw new \InvalidArgumentException(implode(' ', array_slice($prevalidacion['errores'], 0, 5)));
        }

        DB::transaction(function () use ($prevalidacion, $periodo, $usuario): void {
            foreach ($prevalidacion['registros'] as $registro) {
                $this->guardar($registro, $periodo, $usuario, 'importada');
            }
        });

        return $prevalidacion['total'];
    }

    public function plantilla(Carbon|string $periodo): Spreadsheet
    {
        $mes = Carbon::parse($periodo)->startOfMonth()->toDateString();
        $catalogo = $this->catalogoPorCodigo();
        $existentes = CuotaVentaRestaurant::query()->whereDate('periodo', $mes)->get()->keyBy('codigo');

        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Cuotas');
        $hoja->fromArray(['CÓDIGO', 'TIENDA', 'CUOTA SIN IGV', 'CUOTA CON IGV'], null, 'A1');

        $fila = 2;
        foreach ($catalogo as $codigo => $local) {
            $cuota = $existentes->get($codigo);
            $hoja->fromArray([
                $codigo,
                $local->local,
                $cuota?->cuota_sin_igv,
                $cuota?->cuota_con_igv,
            ], null, "A{$fila}");
            $fila++;
        }
        $this->estilizarPlantilla($hoja, $fila - 1);

        $instrucciones = $libro->createSheet();
        $instrucciones->setTitle('Instrucciones');
        $instrucciones->fromArray([
            ['PLANTILLA DE CUOTAS MENSUALES'],
            ['1. No cambies los códigos ni los nombres de tienda.'],
            ['2. Completa las dos columnas de cuota con importes no negativos.'],
            ['3. Selecciona el mes destino antes de importar el archivo.'],
        ], null, 'A1');
        $instrucciones->getColumnDimension('A')->setWidth(78);
        $instrucciones->getStyle('A1')->getFont()->setBold(true);
        $instrucciones->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF59E0B');

        $libro->setActiveSheetIndex(0);

        return $libro;
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
        if (is_numeric($valor)) {
            $numero = (float) $valor;
            if ($numero < 0) {
                throw new \InvalidArgumentException("La cuota {$campo} del código {$codigo} no puede ser negativa.");
            }

            return $numero;
        }
        $texto = str_replace(['S/', ' ', "\u{00A0}"], '', trim((string) $valor));
        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            $texto = strrpos($texto, ',') > strrpos($texto, '.')
                ? str_replace(',', '.', str_replace('.', '', $texto))
                : str_replace(',', '', $texto);
        }
        elseif (str_contains($texto, ',')) $texto = str_replace(',', '.', $texto);
        if (! is_numeric($texto)) throw new \InvalidArgumentException("La cuota {$campo} del código {$codigo} no es numérica.");
        $numero = (float) $texto;
        if ($numero < 0) {
            throw new \InvalidArgumentException("La cuota {$campo} del código {$codigo} no puede ser negativa.");
        }

        return $numero;
    }

    private function normalizarCabecera(string $valor): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtoupper(\Illuminate\Support\Str::ascii($valor))));
    }

    /** @return \Illuminate\Support\Collection<string, CuotaVentaRestaurant> */
    private function catalogoPorCodigo(): \Illuminate\Support\Collection
    {
        return CuotaVentaRestaurant::query()
            ->orderByDesc('periodo')
            ->orderBy('codigo')
            ->get()
            ->unique(fn (CuotaVentaRestaurant $cuota): string => $this->normalizarCodigo($cuota->codigo))
            ->keyBy(fn (CuotaVentaRestaurant $cuota): string => $this->normalizarCodigo($cuota->codigo));
    }

    private function normalizarCodigo(mixed $codigo): string
    {
        return mb_strtoupper(trim((string) $codigo));
    }

    private function normalizarLocal(string $local): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtoupper(\Illuminate\Support\Str::ascii($local))));
    }

    private function estilizarPlantilla(Worksheet $hoja, int $ultimaFila): void
    {
        $hoja->freezePane('A2');
        $hoja->setAutoFilter("A1:D{$ultimaFila}");
        $hoja->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF111827');
        $hoja->getStyle("C2:D{$ultimaFila}")->getNumberFormat()->setFormatCode('#,##0.00');
        $hoja->getColumnDimension('A')->setWidth(16);
        $hoja->getColumnDimension('B')->setWidth(38);
        $hoja->getColumnDimension('C')->setWidth(20);
        $hoja->getColumnDimension('D')->setWidth(20);
    }

    /** @return array<string, mixed> */
    private function snapshot(CuotaVentaRestaurant $cuota): array
    {
        return $cuota->only(['codigo', 'local_id', 'local', 'periodo', 'cuota_sin_igv', 'cuota_con_igv']);
    }
}
