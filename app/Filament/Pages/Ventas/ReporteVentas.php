<?php

namespace App\Filament\Pages\Ventas;

use App\Filament\Concerns\InteractsWithSales;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

class ReporteVentas extends Page implements HasTable
{
    use InteractsWithSales;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Reporte de ventas';

    protected static ?string $title = 'Reporte de ventas';

    protected static string|\UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'ventas/reporte';

    protected string $view = 'filament.pages.ventas.reporte';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('ventas.reporte.view');
    }

    protected function detailPermissionSlug(): string
    {
        return 'ventas.reporte.ver-detalle';
    }

    public function search(): void
    {
        $this->salesPage = 1;
        $this->loadSales();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->salesRecords($page, $recordsPerPage))
            ->columns([
                TextColumn::make('venta_id')->label('Cód.')->weight('medium'),
                TextColumn::make('venta_fecha')->label('Fecha')->toggleable(),
                TextColumn::make('local_descripcion')->label('Local')->searchable()->wrap(),
                TextColumn::make('cliente_descripciion')->label('Cliente')->searchable()->wrap()->toggleable(),
                TextColumn::make('comprobante')->label('Comprobante')->state(fn (array $record): string => trim(($record['venta_tipodoc'] ?? '').' '.($record['venta_seriedoc'] ?? '').'-'.($record['venta_numdoc'] ?? ''), ' -') ?: '—')->toggleable(),
                TextColumn::make('venta_subtotal')->label('Subtotal')->numeric(2)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('impuestos')->label('Impuestos')->numeric(2)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('venta_total')->label('Total')->numeric(2)->alignEnd()->sortable(),
                TextColumn::make('venta_formapago')->label('Pago')->toggleable(),
                TextColumn::make('venta_estado')->label('Estado')->badge(),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay ventas para los filtros seleccionados.');
    }

    protected function salesRecords(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        if (! $this->hasSearched) {
            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page);
        }

        try {
            $filters = $this->buildSalesFilters();
            $filters['pagina'] = $page;
            $filters['registros'] = $recordsPerPage;
            $result = $this->salesGateway()->sales($filters);
            $rows = collect($result['rows'] ?? []);
            $total = (int) ($result['total'] ?? $rows->count());
            $this->salesRows = $rows->all();
            $this->salesTotal = $total;

            return new LengthAwarePaginator($rows, $total, $recordsPerPage, $page, ['path' => request()->url(), 'pageName' => 'reporteVentasPage']);
        } catch (Throwable $exception) {
            $this->resultError = $this->friendlySalesError($exception);

            return new LengthAwarePaginator(collect(), 0, $recordsPerPage, $page);
        }
    }
}
