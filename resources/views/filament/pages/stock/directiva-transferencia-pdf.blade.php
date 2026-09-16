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
    table { border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #d1d5db; padding: 2px 1px; vertical-align: middle; }
    th { background: #dce6f1; font-weight: bold; text-align: center; }
    {{--
        overflow:hidden + position:relative en th.local, con el span rotado
        en position:absolute y CENTRADO (top/left 50% + margin negativo
        calculado por local, ver más abajo) -- necesario porque dompdf
        calcula el ancho MÍNIMO de una columna a partir del texto SIN rotar
        (ignora el transform), así que un simple "inline-block + rotate"
        agranda la columna al ancho del nombre completo en vez de
        angostarla. Sacando el span del flujo normal (absolute) su tamaño ya
        no cuenta para el ancho de la columna -- comprobado en vivo probando
        varias variantes (bottom-anchor recortaba el nombre, top:100% con
        transform-origin no renderizaba nada). El margin-left negativo tiene
        que ser la MITAD del ancho real del texto (aprox. 4.5px por
        carácter, DejaVu Sans Bold 7px) para que el centrado post-rotación
        quede exacto -- por eso se calcula por local, no un valor fijo.
    --}}
    th.local { height: 150px; font-size: 7px; padding: 0; overflow: hidden; position: relative; }
    th.local .giro { position: absolute; top: 50%; left: 50%; margin-top: -4px; white-space: nowrap; transform: rotate(-90deg); text-align: right; }
    td.num { text-align: right; font-size: 7px; }
    tbody tr:nth-child(even) td { background: #f3f4f6; }
    tr.total td, td.total { font-weight: bold; background: #dce6f1 !important; }
    .leyenda { margin-top: 6px; font-size: 7px; color: #6b7280; }
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
        <p>Generado por: {{ $usuarioNombre }} · {{ $generadoEn }}</p>
    </div>

    <div class="pie-pagina">Generado el {{ $generadoEn }}</div>

    {{--
        dompdf con table-layout:fixed no respeta de forma confiable un ancho
        puesto por CLASE en cada <th>, y si además se fuerza un ancho total
        en <table>, termina dividiendo todo en partes IGUALES entre las
        columnas (Producto quedaba tan angosto como una columna de local) --
        comprobado en vivo generando el PDF real y probando variantes. El
        patrón que sí funciona: <colgroup><col style="width:..."> por
        columna, SIN ningún ancho explícito en <table> (deja que el ancho
        total salga de la suma de las columnas del colgroup).
    --}}
    <table>
        <colgroup>
            <col style="width: 110px;">
            <col style="width: 34px;">
            @foreach($locales as $local)
                <col style="width: 15px;">
            @endforeach
            <col style="width: 15px;">
        </colgroup>
        @php
            // Ancho real aproximado del texto rotado (4.5px/carácter a 7px
            // DejaVu Sans Bold + 6px de margen) -- la mitad como
            // margin-left negativo centra el span ya rotado dentro de la
            // columna angosta. Ver comentario de th.local .giro arriba.
            $anchoGiro = fn (string $texto): float => strlen($texto) * 4.5 + 6;
        @endphp
        <thead>
            <tr>
                <th class="producto">Producto</th>
                <th class="sku">SKU</th>
                @foreach($locales as $local)
                    <th class="local"><span class="giro" style="width: {{ $anchoGiro($local->local_nombre) }}px; margin-left: -{{ $anchoGiro($local->local_nombre) / 2 }}px;">{{ $local->local_nombre }}</span></th>
                @endforeach
                <th class="local"><span class="giro" style="width: {{ $anchoGiro('TOTAL') }}px; margin-left: -{{ $anchoGiro('TOTAL') / 2 }}px;">TOTAL</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($filas as $fila)
                <tr>
                    <td>{{ $fila['nombre'] }}</td>
                    <td>{{ $fila['codigo'] }}</td>
                    @foreach($fila['celdas'] as $celda)
                        <td class="num">{{ number_format($celda['cantidad'], 0) }}</td>
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
