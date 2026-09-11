<?php

namespace App\Services;

use App\Models\VentaExternaAuditoria;
use App\Models\VentaExternaDiaria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VentasExternasService
{
    /** @param array<string, mixed> $data */
    public function registrar(array $data, ?int $userId): VentaExternaDiaria
    {
        return DB::transaction(function () use ($data, $userId): VentaExternaDiaria {
            $this->validarUnicaFechaCanal((int) $data['canal_id'], (string) $data['fecha']);
            $venta = VentaExternaDiaria::create($this->payload($data, $userId, true));
            $this->auditar($venta, 'creada', null, $venta->fresh()->toArray(), $userId);

            return $venta;
        });
    }

    /** @param array<string, mixed> $data */
    public function actualizar(VentaExternaDiaria $venta, array $data, ?int $userId): VentaExternaDiaria
    {
        return DB::transaction(function () use ($venta, $data, $userId): VentaExternaDiaria {
            if ($venta->estado !== 'activa') {
                throw ValidationException::withMessages(['fecha' => 'No se puede editar una venta anulada.']);
            }

            $this->validarUnicaFechaCanal((int) $data['canal_id'], (string) $data['fecha'], $venta->id);
            $antes = $venta->fresh()->toArray();
            $venta->fill($this->payload($data, $userId));
            $venta->save();
            $this->auditar($venta, 'actualizada', $antes, $venta->fresh()->toArray(), $userId);

            return $venta;
        });
    }

    public function anular(VentaExternaDiaria $venta, ?int $userId, ?string $motivo = null): void
    {
        DB::transaction(function () use ($venta, $userId): void {
            if ($venta->estado === 'anulada') {
                return;
            }

            $antes = $venta->fresh()->toArray();
            $venta->update([
                'estado' => 'anulada',
                'motivo_anulacion' => filled($motivo) ? trim((string) $motivo) : null,
                'anulado_en' => now(),
                'anulado_por' => $userId,
                'actualizado_por' => $userId,
            ]);
            $this->auditar($venta, 'anulada', $antes, $venta->fresh()->toArray(), $userId);
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function payload(array $data, ?int $userId, bool $new = false): array
    {
        $sinIgv = round((float) ($data['venta_sin_igv'] ?? 0), 2);
        $igv = round((float) ($data['igv'] ?? 0), 2);

        $payload = [
            'canal_id' => (int) $data['canal_id'],
            'fecha' => (string) $data['fecha'],
            'venta_sin_igv' => $sinIgv,
            'igv' => $igv,
            'venta_con_igv' => round($sinIgv + $igv, 2),
            'tickets' => (int) ($data['tickets'] ?? 0),
            'costo_sin_igv' => round((float) ($data['costo_sin_igv'] ?? 0), 2),
            'observacion' => filled($data['observacion'] ?? null) ? trim((string) $data['observacion']) : null,
            'estado' => 'activa',
            'actualizado_por' => $userId,
            'anulado_en' => null,
            'anulado_por' => null,
        ];

        if ($new) {
            $payload['registrado_por'] = $userId;
        }

        return $payload;
    }

    private function validarUnicaFechaCanal(int $canalId, string $fecha, ?int $exceptId = null): void
    {
        $exists = VentaExternaDiaria::query()->where('canal_id', $canalId)->whereDate('fecha', $fecha)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['fecha' => 'Ya existe una venta para este canal y fecha. Edita el registro existente.']);
        }
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed>|null $despues */
    private function auditar(VentaExternaDiaria $venta, string $accion, ?array $antes, ?array $despues, ?int $userId): void
    {
        VentaExternaAuditoria::create([
            'venta_externa_diaria_id' => $venta->id,
            'accion' => $accion,
            'antes' => $antes,
            'despues' => $despues,
            'usuario_id' => $userId,
        ]);
    }
}
