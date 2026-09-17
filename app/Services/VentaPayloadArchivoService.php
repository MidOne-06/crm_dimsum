<?php

namespace App\Services;

use App\Models\Venta;
use App\Models\VentaPayloadArchivo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VentaPayloadArchivoService
{
    /**
     * Copia el payload a un disco externo y confirma su hash antes de dejar
     * evidencia en la base. No elimina `ventas.raw`; esa decisión siempre la
     * toma explícitamente el comando de retención.
     *
     * @return array{path: string, sha256: string, bytes_originales: int, bytes_comprimidos: int}
     */
    public function archivar(Venta $venta, string $disk): array
    {
        $this->validarDiscoExterno($disk);

        if (! is_array($venta->raw)) {
            throw new RuntimeException("La venta {$venta->venta_id} no tiene un payload JSON para archivar.");
        }

        $json = json_encode($venta->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $comprimido = gzencode($json, 9);
        if ($comprimido === false) {
            throw new RuntimeException("No se pudo comprimir el payload de la venta {$venta->venta_id}.");
        }

        $fecha = $venta->venta_fecha?->copy() ?? now();
        $path = sprintf(
            'ventas-payloads/%s/%s/%s/%s.json.gz',
            $fecha->format('Y'),
            $fecha->format('m'),
            $fecha->format('d'),
            $venta->venta_id,
        );
        $sha256 = hash('sha256', $json);
        $filesystem = Storage::disk($disk);

        if (! $filesystem->put($path, $comprimido)) {
            throw new RuntimeException("No se pudo escribir el archivo externo de la venta {$venta->venta_id}.");
        }

        $guardado = $filesystem->get($path);
        $restaurado = is_string($guardado) ? gzdecode($guardado) : false;
        if ($restaurado === false || ! hash_equals($sha256, hash('sha256', $restaurado))) {
            throw new RuntimeException("La verificación del archivo externo falló para la venta {$venta->venta_id}.");
        }

        VentaPayloadArchivo::query()->updateOrCreate(
            ['venta_id' => $venta->venta_id],
            [
                'disk' => $disk,
                'path' => $path,
                'sha256' => $sha256,
                'bytes_originales' => strlen($json),
                'bytes_comprimidos' => strlen($comprimido),
                'formato' => 'json.gz',
                'archivado_en' => now(),
                'verificado_en' => now(),
            ],
        );

        return [
            'path' => $path,
            'sha256' => $sha256,
            'bytes_originales' => strlen($json),
            'bytes_comprimidos' => strlen($comprimido),
        ];
    }

    private function validarDiscoExterno(string $disk): void
    {
        $configuracion = config("filesystems.disks.{$disk}");
        if (! is_array($configuracion)) {
            throw new RuntimeException("El disco '{$disk}' no está configurado.");
        }

        if (($configuracion['driver'] ?? null) === 'local') {
            throw new RuntimeException("El disco '{$disk}' es local al VPS y no sirve como respaldo verificable.");
        }
    }
}
