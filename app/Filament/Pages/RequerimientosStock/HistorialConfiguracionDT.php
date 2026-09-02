<?php

namespace App\Filament\Pages\RequerimientosStock;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Trazabilidad de toda la configuración de la Directiva de Transferencia
 * (tapers + los 9 módulos de casuística operativa): quién creó/editó/borró
 * cada fila, cuándo, y con qué valores. Fuente: configuracion_dt_eventos,
 * poblada automáticamente por App\Models\Concerns\RegistraTrazabilidad.
 */
class HistorialConfiguracionDT extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Historial de cambios';
    protected static ?string $title = 'Historial de cambios — Directiva de Transferencia';
    protected static string|\UnitEnum|null $navigationGroup = 'Configuración DT';
    protected static ?int $navigationSort = 31;
    protected static ?string $slug = 'configuracion-dt/historial';
    protected string $view = 'filament.pages.requerimientos-stock.historial-configuracion-dt';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('tapers.manage');
    }

    /** @return array<string, string> */
    protected static function tablaLabels(): array
    {
        return [
            'taper_tipos' => 'Tipos de taper',
            'producto_tapers' => 'Capacidad por producto (taper)',
            'local_taper_capacidades' => 'Capacidad por local (taper)',
            'local_logistica_configs' => 'Logística por local',
            'producto_vida_utils' => 'Vida útil de productos',
            'fabrica_capacidad_productos' => 'Capacidad de FABRICA',
            'configuracion_prorrateos' => 'Configuración de prorrateo',
            'prioridad_local_prorrateos' => 'Prioridad manual de reparto',
            'regla_sustitucion_productos' => 'Sustitución de productos',
            'vehiculo_capacidads' => 'Capacidad de vehículos',
            'cantidad_estandar_arranques' => 'Cantidad de arranque',
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->baseQuery())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Cuándo')->dateTime('d/m/Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('tabla')->label('Módulo')->formatStateUsing(fn (?string $s): string => static::tablaLabels()[$s] ?? $s)->badge(),
                Tables\Columns\TextColumn::make('registro_id')->label('Fila #')->numeric(),
                Tables\Columns\TextColumn::make('accion')->label('Acción')->badge()
                    ->color(fn (?string $s): string => match ($s) { 'creado' => 'success', 'eliminado' => 'danger', default => 'gray' }),
                Tables\Columns\TextColumn::make('usuario_nombre')->label('Usuario')->default('—'),
                Tables\Columns\TextColumn::make('datos_despues')->label('Cambios')->wrap()
                    ->state(fn (object $r): string => $this->resumenCambios($r))
                    ->limit(200),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tabla')->label('Módulo')->options(static::tablaLabels()),
                Tables\Filters\SelectFilter::make('accion')->label('Acción')->options(['creado' => 'Creado', 'actualizado' => 'Actualizado', 'eliminado' => 'Eliminado']),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Sin cambios registrados todavía.');
    }

    private function baseQuery(): Builder
    {
        // Modelo anónimo sobre una tabla sin Eloquent model dedicado -- solo
        // para reusar InteractsWithTable con filtros/orden/paginación.
        return (new class extends Model {
            protected $table = 'configuracion_dt_eventos';
            public $timestamps = false;
            protected $guarded = [];
        })->newQuery();
    }

    private function resumenCambios(object $registro): string
    {
        $despues = $registro->datos_despues ? json_decode((string) $registro->datos_despues, true) : null;
        if (! $despues) {
            return $registro->accion === 'eliminado' ? '(fila eliminada)' : '—';
        }
        $partes = [];
        foreach ($despues as $campo => $valor) {
            if (in_array($campo, ['created_at', 'updated_at', 'id'], true)) continue;
            $partes[] = "{$campo}: ".(is_scalar($valor) ? $valor : json_encode($valor, JSON_UNESCAPED_UNICODE));
        }

        return implode(' · ', $partes);
    }
}
