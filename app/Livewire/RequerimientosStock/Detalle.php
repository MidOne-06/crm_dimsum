<?php

namespace App\Livewire\RequerimientosStock;

use App\Models\RequerimientoStockHistorico;
use App\Services\RequerimientoStockGatewayClient;
use App\Services\RequerimientoStockHistoricoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Throwable;

class Detalle extends Component
{
    public string $erpId;
    /** @var array{cabecera: array<string, mixed>, detalles: array<int, array<string, mixed>>}|null */
    public ?array $detalle = null;
    public bool $sincronizando = false;
    public ?string $syncError = null;
    /** @var array<int, array{fecha: string, tipo: string, detalles: int}> */
    public array $historial = [];
    /** @var array<int, array<string, mixed>> */
    public array $historialRestaurant = [];

    public function mount(string $erpId): void
    {
        abort_unless(auth()->user()?->hasPermission('requerimientos-stock.crear'), 403);
        $this->erpId = $erpId;
        $this->detalle = app(RequerimientoStockHistoricoService::class)->obtenerLocal($erpId);
        $this->cargarHistorial();
    }

    public function sincronizar(): void
    {
        $this->sincronizando = true;
        $this->syncError = null;

        try {
            $gateway = app(RequerimientoStockGatewayClient::class);
            $remoto = $gateway->detalle($this->erpId);
            app(RequerimientoStockHistoricoService::class)->sincronizar($remoto);
            $this->detalle = app(RequerimientoStockHistoricoService::class)->obtenerLocal($this->erpId);
            $this->historialRestaurant = $gateway->historial($this->erpId);
            $this->cargarHistorial();
        } catch (Throwable $exception) {
            $this->syncError = 'No se pudo actualizar desde Restaurant. Se muestra la última copia local.';
            RequerimientoStockHistorico::query()->where('erp_id', $this->erpId)->update([
                'ultima_sincronizacion_error' => $exception->getMessage(),
            ]);
        } finally {
            $this->sincronizando = false;
        }
    }

    public function render()
    {
        return view('livewire.requerimientos-stock.detalle');
    }

    private function cargarHistorial(): void
    {
        $historyId = RequerimientoStockHistorico::query()->where('erp_id', $this->erpId)->value('id');
        if (! $historyId) { $this->historial = []; return; }

        $this->historial = DB::table('requerimientos_stock_historico_eventos')
            ->where('requerimiento_stock_historico_id', $historyId)->latest('id')->limit(20)
            ->get(['tipo', 'despues', 'created_at'])
            ->map(function (object $event): array {
                $after = is_string($event->despues) ? json_decode($event->despues, true) : (array) $event->despues;
                return [
                    'fecha' => filled($event->created_at ?? null) ? Carbon::parse($event->created_at)->format('d/m/Y H:i:s') : '',
                    'tipo' => $event->tipo === 'creacion' ? 'Creación' : 'Sincronización',
                    'detalles' => count($after['detalles'] ?? []),
                ];
            })->all();
    }
}
