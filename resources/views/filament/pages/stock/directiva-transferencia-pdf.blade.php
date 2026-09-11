<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 16px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111827; }
    .encabezado { width: 100%; margin-bottom: 2px; }
    .encabezado .logo { height: 28px; }
    .titulo { margin: 4px 0 6px; font-size: 20px; text-align: center; }
    .meta { margin: 0 0 10px; color: #4b5563; }
    .meta p { margin: 0 0 2px; }
    .meta .despacho { text-transform: capitalize; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #d1d5db; padding: 4px 3px; vertical-align: middle; }
    th { background: #dce6f1; font-weight: bold; text-align: center; }
    th.local { height: 125px; font-size: 7px; padding: 2px 0; }
    th.local .giro { display: inline-block; transform: rotate(-90deg); white-space: nowrap; }
    td.num { text-align: right; }
    tbody tr:nth-child(even) td { background: #f3f4f6; }
    tr.total td, td.total { font-weight: bold; background: #dce6f1 !important; }
    .producto { width: 16%; } .sku { width: 6%; }
    .pie-pagina {
        position: fixed;
        bottom: -22px;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 7px;
        color: #6b7280;
        border-top: 1px solid #d1d5db;
        padding-top: 3px;
    }
</style>
</head>
<body>
    <div class="encabezado">
        @if($logoDataUri)
            <img src="{{ $logoDataUri }}" alt="Logo" class="logo">
        @endif
    </div>
    <h1 class="titulo">Directiva de Transferencia</h1>
    <div class="meta">
        <p class="despacho">Despacho: {{ $fecha }}</p>
        <p>Generado por: {{ $usuarioNombre }} · {{ $generadoEn }} (hora peruana)</p>
    </div>

    <div class="pie-pagina">Generado el {{ $generadoEn }} (hora peruana)</div>

    <table>
        <thead>
            <tr>
                <th class="producto">Producto</th>
                <th class="sku">SKU</th>
                @foreach($locales as $local)
                    <th class="local"><span class="giro">{{ $local->local_nombre }}</span></th>
                @endforeach
                <th class="local"><span class="giro">TOTAL</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($filas as $fila)
                <tr>
                    <td>{{ $fila['nombre'] }}</td>
                    <td>{{ $fila['codigo'] }}</td>
                    @foreach($fila['cantidades'] as $cantidad)
                        <td class="num">{{ number_format($cantidad, 0) }}</td>
                    @endforeach
                    <td class="num total">{{ number_format($fila['total'], 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $locales->count() + 3 }}">Sin sugerencias calculadas para esta fecha</td></tr>
            @endforelse
            @if($filas->isNotEmpty())
                <tr class="total">
                    <td colspan="2">TOTAL</td>
                    @foreach($totalesColumna as $total)
                        <td class="num">{{ number_format($total, 0) }}</td>
                    @endforeach
                    <td class="num">{{ number_format($granTotal, 0) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
