<?php

namespace App\Filament\Resources;

use App\Filament\Exports\OpmProductoExporter;
use App\Filament\Resources\OpmProductoResource\Pages;
use App\Models\OpmProducto;
use App\Support\OpmExecutionScope;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Filament\Support\Enums\Width;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class OpmProductoResource extends Resource
{
    protected static ?string $model = OpmProducto::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('id')->label('ID')->disabled(),
            TextInput::make('nombre_producto')->label('Nombre')->required(),
            TextInput::make('principio_activo')->label('Principio activo'),
            TextInput::make('concentracion')->label('Concentración'),
            TextInput::make('forma')->label('Forma farmacéutica'),
            TextInput::make('grupo')->label('Grupo')->numeric(),
            TextInput::make('cod_grupo_ff')->label('Cod. Grupo FF'),
            TextInput::make('cant_precios')->label('Cant. Establecimientos')->numeric(),
            TextInput::make('min_precio1')->label('Precio mín. (S/)')->numeric(),
            TextInput::make('max_precio1')->label('Precio máx. (S/)')->numeric(),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema([
                    Section::make('Producto')
                        ->schema([
                            TextEntry::make('nombre_producto')
                                ->label('Nombre')
                                ->columnSpanFull(),
                            TextEntry::make('principio_activo')->label('Principio activo'),
                            TextEntry::make('concentracion')->label('Concentración'),
                            TextEntry::make('forma')->label('Forma farmacéutica'),
                            TextEntry::make('grupo')->label('Grupo'),
                            TextEntry::make('cod_grupo_ff')->label('Cód. grupo FF'),
                        ])
                        ->columns(2),

                    Section::make('Origen de datos')
                        ->schema([
                            TextEntry::make('parametro.nombre')->label('Parámetro'),
                            TextEntry::make('ejecucion.id')
                                ->label('Ejecución')
                                ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : 'Histórica'),
                            TextEntry::make('ejecucion.iniciado_at')->label('Ejecutada')->dateTime('d/m/Y H:i'),
                            TextEntry::make('ejecucion.estado')->label('Estado')->badge(),
                        ])
                        ->columns(2),

                    Section::make('Resumen de precios')
                        ->schema([
                            TextEntry::make('cant_precios')->label('Establecimientos')->numeric(),
                            TextEntry::make('min_precio1')->label('Precio mínimo (S/)')->money('PEN'),
                            TextEntry::make('max_precio1')->label('Precio máximo (S/)')->money('PEN'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar Excel')
                    ->modalHeading('Exportar productos a Excel')
                    ->modalSubmitActionLabel('Generar archivo')
                    ->exporter(OpmProductoExporter::class)
                    ->modifyQueryUsing(function (Builder $query, array $options): Builder {
                        return $query
                            ->when(
                                filled($options['origenes'] ?? []),
                                fn (Builder $query): Builder => $query->whereIn('ejecucion_id', $options['origenes']),
                            )
                            ->when(
                            $options['origen_desde'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereHas('ejecucion', fn (Builder $executionQuery): Builder => $executionQuery->where('iniciado_at', '>=', $date)),
                            )
                            ->when(
                            $options['origen_hasta'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereHas('ejecucion', fn (Builder $executionQuery): Builder => $executionQuery->where('iniciado_at', '<=', $date)),
                            );
                    })
                    ->chunkSize(500)
                    ->columnMappingColumns(2),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('parametro.nombre')
                    ->label('Parámetro')
                    ->badge()
                    ->color('info')
                    ->searchable(
                        true,
                        function (Builder $query, string $search): Builder {
                            $normalized = mb_strtoupper(Str::ascii($search));

                            return $query->whereHas('candidatos', fn (Builder $candidates) => $candidates
                                ->where('consulta_normalizada', $normalized));
                        },
                    )
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('ejecucion.id')
                    ->label('Origen')
                    ->headerTooltip('Parámetro y ejecución de donde procede este registro')
                    ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : 'Histórica')
                    ->description(fn (OpmProducto $record): ?string => trim(implode(' · ', array_filter([
                        $record->parametro?->nombre,
                        $record->ejecucion?->iniciado_at?->format('d/m/Y H:i'),
                    ]))))
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->tooltip(fn (OpmProducto $record): string => trim(implode(' · ', array_filter([
                        $record->parametro?->nombre,
                        $record->ejecucion?->iniciado_at?->format('d/m/Y H:i'),
                    ]))))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nombre_producto')
                    ->label('Nombre del Producto')
                    ->description(fn (OpmProducto $record): ?string => $record->principio_activo && $record->principio_activo !== $record->nombre_producto
                        ? "Principio activo: {$record->principio_activo}"
                        : null)
                    ->searchable()
                    ->sortable()
                    ->limit(55)
                    ->tooltip(fn (OpmProducto $record): string => $record->nombre_producto),
                Tables\Columns\TextColumn::make('concentracion')
                    ->label('Concentración')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn (OpmProducto $record): ?string => $record->concentracion),
                Tables\Columns\TextColumn::make('forma')
                    ->label('Forma Farmacéutica')
                    ->toggleable()
                    ->limit(36)
                    ->tooltip(fn (OpmProducto $record): ?string => $record->forma),
                Tables\Columns\TextColumn::make('cant_precios')
                    ->label('Establec.')
                    ->headerTooltip('Cantidad de establecimientos con precios registrados')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('min_precio1')
                    ->label('Precio Mín.')
                    ->headerTooltip('Precio mínimo registrado (S/)')
                    ->money('PEN')
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_precio1')
                    ->label('Precio Máx.')
                    ->headerTooltip('Precio máximo registrado (S/)')
                    ->money('PEN')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('forma')
                    ->label('Forma farmacéutica')
                    ->options(fn () => OpmProducto::query()
                        ->whereNotNull('forma')
                        ->distinct()
                        ->orderBy('forma')
                        ->pluck('forma', 'forma')
                        ->toArray()),
                Tables\Filters\Filter::make('con_precios')
                    ->label('Con precios disponibles')
                    ->query(fn ($query) => $query->where('cant_precios', '>', 0)),
                Tables\Filters\Filter::make('alcance')
                    ->label('Alcance de datos')
                    ->schema([
                        Grid::make(['default' => 1, 'lg' => 3])->schema([
                            Select::make('ejecuciones')
                                ->label('Origen / corrida')
                                ->multiple()
                                ->searchable()
                                ->options(fn (Get $get): array => OpmExecutionScope::options($get('desde'), $get('hasta'))),
                            DateTimePicker::make('desde')
                                ->label('Desde')
                                ->seconds(false)
                                ->live(),
                            DateTimePicker::make('hasta')
                                ->label('Hasta')
                                ->seconds(false)
                                ->live(),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => OpmExecutionScope::apply($query, $data))
                    ->indicateUsing(fn (array $data): array => filled($label = OpmExecutionScope::label($data))
                        ? [Indicator::make("Alcance: {$label}")->color('primary')]
                        : []),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(1)
            ->deferFilters(false)
            ->actions([
                ViewAction::make()
                    ->modal()
                    ->modalHeading(fn (OpmProducto $record): string => "Producto: {$record->nombre_producto}")
                    ->modalWidth(Width::FiveExtraLarge)
                    ->infolist(fn (Schema $schema): Schema => static::infolist($schema)),
            ])
            ->defaultSort('nombre_producto')
            ->striped();
    }

    public static function getRelations(): array
    {
        return [
            OpmProductoResource\RelationManagers\PreciosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOpmProductos::route('/'),
            'view' => Pages\ViewOpmProducto::route('/{record}'),
        ];
    }
}
