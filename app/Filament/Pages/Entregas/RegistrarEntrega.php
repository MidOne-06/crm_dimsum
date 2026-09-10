<?php

namespace App\Filament\Pages\Entregas;

use App\Models\EntregaDespacho;
use App\Models\LocalTransportista;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Pantalla móvil del transportista: marca "despacho entregado" a un local.
 *
 * Resolución de qué locales ve (sin GPS -- pedido explícito del usuario,
 * el sitio corre en HTTP y el navegador bloquea geolocalización ahí):
 *  - Locales donde es TITULAR y NO tiene ausencia vigente hoy.
 *  - Locales donde es SUPLENTE y el titular de ese local SÍ tiene ausencia
 *    vigente hoy -> al marcar, la entrega queda auto-marcada como reemplazo
 *    con el motivo de la ausencia.
 *  - Con el toggle "Cubrir de todos modos": además, los locales donde es
 *    suplente aunque el titular NO tenga ausencia registrada (caso "se
 *    enfermó de golpe y nadie lo cargó") -> al marcar pide el motivo a mano.
 *
 * NO toca guías internas ni `recepcionada` -- control puramente operativo
 * para el histórico y el promedio de hora de entrega.
 */
class RegistrarEntrega extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Registrar entrega';
    protected static ?string $title = 'Registrar entrega de despacho';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'entregas/registrar';
    protected string $view = 'filament.pages.entregas.registrar-entrega';

    public bool $cubrirDeMasModos = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('entregas.registrar');
    }

    /**
     * @return array<int, array{local_id: string, local_nombre: string, rol: string, es_reemplazo: bool, motivo_auto: ?string, ya_entregado: bool}>
     */
    public function localesDisponibles(): array
    {
        $user = auth()->user();
        $hoy = today();
        $ausenteHoy = $user->ausenteEn($hoy);

        $asignaciones = LocalTransportista::where('user_id', $user->id)->get();
        $out = [];

        foreach ($asignaciones as $asignacion) {
            $esTitular = ! $asignacion->es_suplente;

            if ($esTitular) {
                // El titular ausente hoy no debería entregar.
                if ($ausenteHoy) {
                    continue;
                }
                $out[] = $this->armarFila($asignacion, 'titular', false, null, $hoy);

                continue;
            }

            // Es suplente: ver el local si el titular está ausente hoy...
            $titular = LocalTransportista::where('local_id', $asignacion->local_id)
                ->where('es_suplente', false)->first();
            $titularAusente = $titular && User::find($titular->user_id)?->ausenteEn($hoy);

            if ($titularAusente) {
                $motivo = \App\Models\TransportistaAusencia::where('user_id', $titular->user_id)
                    ->whereDate('fecha_inicio', '<=', $hoy->toDateString())
                    ->whereDate('fecha_fin', '>=', $hoy->toDateString())
                    ->value('motivo');
                $out[] = $this->armarFila($asignacion, 'suplente', true, $motivo, $hoy);

                continue;
            }

            // ...o si activó "cubrir de todos modos" (motivo a mano).
            if ($this->cubrirDeMasModos) {
                $out[] = $this->armarFila($asignacion, 'suplente', true, null, $hoy);
            }
        }

        usort($out, fn ($a, $b) => strcmp($a['local_nombre'], $b['local_nombre']));

        return $out;
    }

    /** @return array{local_id: string, local_nombre: string, rol: string, es_reemplazo: bool, motivo_auto: ?string, ya_entregado: bool} */
    private function armarFila(LocalTransportista $asignacion, string $rol, bool $esReemplazo, ?string $motivoAuto, Carbon $hoy): array
    {
        $nombre = $asignacion->local_nombre
            ?: (\App\Models\StockInicialLocal::where('local_id', $asignacion->local_id)->value('local_nombre') ?? $asignacion->local_id);

        $yaEntregado = EntregaDespacho::where('local_id', $asignacion->local_id)
            ->whereDate('fecha_hora', $hoy->toDateString())
            ->exists();

        return [
            'local_id' => $asignacion->local_id,
            'local_nombre' => $nombre,
            'rol' => $rol,
            'es_reemplazo' => $esReemplazo,
            'motivo_auto' => $motivoAuto,
            'ya_entregado' => $yaEntregado,
        ];
    }

    public function toggleCubrir(): void
    {
        $this->cubrirDeMasModos = ! $this->cubrirDeMasModos;
    }

    public function entregarAction(): Action
    {
        return Action::make('entregar')
            ->label('Marcar entregado')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalWidth('md')
            ->modalHeading(fn (array $arguments): string => 'Entregar a '.($arguments['local_nombre'] ?? ''))
            ->modalSubmitActionLabel('Confirmar entrega')
            ->schema(fn (array $arguments): array => array_filter([
                $arguments['es_reemplazo'] && ! ($arguments['motivo_auto'] ?? null)
                    ? Select::make('motivo_reemplazo')
                        ->label('Motivo del reemplazo (el titular no vino)')
                        ->options([
                            'Titular no disponible' => 'Titular no disponible',
                            'Titular enfermo' => 'Titular enfermo',
                            'Reasignación del día' => 'Reasignación del día',
                            'Otro' => 'Otro',
                        ])
                        ->native(false)->required()
                    : null,
                FileUpload::make('foto')
                    ->label('Foto de la entrega (opcional)')
                    ->image()
                    ->disk('public')
                    ->directory('entregas')
                    ->visibility('public')
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('1600')
                    ->imageResizeTargetHeight('1600')
                    ->maxSize(12288),
                Textarea::make('observacion')->label('Observación (opcional)')->rows(2)->maxLength(500),
            ]))
            ->action(function (array $arguments, array $data): void {
                $this->guardarEntrega($arguments, $data);
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     */
    private function guardarEntrega(array $arguments, array $data): void
    {
        abort_unless(auth()->user()?->hasPermission('entregas.registrar'), 403);

        $localId = (string) ($arguments['local_id'] ?? '');
        // Re-valida contra la lista real de este instante -- nadie puede
        // colar un local que no le toca vía el payload de Livewire.
        $fila = collect($this->localesDisponibles())->firstWhere('local_id', $localId);
        if (! $fila) {
            Notification::make()->danger()->title('Local no disponible')->body('Ese local ya no está en tu lista de entregas de hoy.')->send();

            return;
        }

        $ahora = now();
        EntregaDespacho::create([
            'user_id' => auth()->id(),
            'local_id' => $localId,
            'local_nombre' => $fila['local_nombre'],
            'fecha_hora' => $ahora,
            'dia_semana' => $ahora->dayOfWeekIso,
            'rol_entrega' => $fila['rol'],
            'es_reemplazo' => $fila['es_reemplazo'],
            'motivo_reemplazo' => $fila['es_reemplazo']
                ? ($fila['motivo_auto'] ?: ($data['motivo_reemplazo'] ?? null))
                : null,
            'foto_path' => $data['foto'] ?? null,
            'observacion' => $data['observacion'] ?? null,
        ]);

        Notification::make()->success()->title('Entrega registrada')
            ->body("{$fila['local_nombre']} -- ".$ahora->format('d/m/Y H:i'))->send();
    }
}
