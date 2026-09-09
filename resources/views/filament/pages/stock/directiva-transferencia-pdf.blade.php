<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20px 16px 30px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111827; }
    .encabezado { width: 100%; margin-bottom: 4px; }
    .encabezado img { height: 32px; vertical-align: middle; }
    .encabezado .marca { display: inline-block; vertical-align: middle; margin-left: 8px; }
    .encabezado h1 { margin: 0; font-size: 18px; display: inline-block; vertical-align: middle; }
    .meta { margin: 6px 0 10px; color: #4b5563; }
    .meta p { margin: 0 0 2px; }
    .meta .despacho { text-transform: capitalize; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #d1d5db; padding: 4px 3px; vertical-align: middle; }
    th { background: #dce6f1; font-weight: bold; text-align: center; }
    th.local { writing-mode: vertical-rl; height: 95px; font-size: 7px; }
    td.num { text-align: right; }
    td.total, tr.total td { font-weight: bold; background: #dce6f1; }
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
            <img src="{{ $logoDataUri }}" alt="Logo">
        @endif
        <span class="marca"><h1>Directiva de Transferencia</h1></span>
    </div>
    <div class="meta">
        <p class="despacho">Despacho: {{ $fecha }}</p>
        <p>Generado por: {{ $usuarioNombre }}</p>
    </div>

    <div class="pie-pagina">Generado el {{ $generadoEn }} (hora peruana)</div>

    <table>
        <thead>
            <tr>
                <th class="producto">Producto</th>
                <th class="sku">SKU</th>
                @foreach($locales as $local)
                    <th class="local">{{ $local->local_nombre }}</th>
                @endforeach
                <th class="local">TOTAL</th>
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
