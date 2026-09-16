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
    th, td { border: 1px solid #d1d5db; vertical-align: middle; }
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
        que ser la MITAD del ancho real del texto (aprox. 7px por carácter a
        11px DejaVu Sans Bold, escalado desde el 4.5px/carácter medido a
        7px) para que el centrado post-rotación quede exacto -- por eso se
        calcula por local, no un valor fijo.
    --}}
    th.local { font-size: 9px; padding: 0; overflow: hidden; position: relative; }
    th.local .giro { position: absolute; top: 50%; left: 50%; margin-top: -5px; white-space: nowrap; transform: rotate(-90deg); text-align: right; }
    td.num { text-align: right; }
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
            <col style="width: 170px;">
            <col style="width: 55px;">
            @foreach($locales as $local)
                <col style="width: 24px;">
            @endforeach
            <col style="width: 24px;">
        </colgroup>
        @php
            // Ancho real aproximado del texto rotado del encabezado
            // (5.7px/carácter a 9px DejaVu Sans Bold + 10px de margen) --
            // la mitad como margin-left negativo centra el span ya rotado
            // dentro de la columna angosta. Ver comentario de th.local
            // .giro arriba.
            $anchoGiro = fn (string $texto): float => strlen($texto) * 5.7 + 10;
            $nombreMasLargo = $locales->push((object) ['local_nombre' => 'TOTAL'])->max(fn ($l) => strlen($l->local_nombre));
            $alturaHeader = min(200, max(90, $anchoGiro(str_repeat('X', $nombreMasLargo)) + 14));

            // Alto de fila y tamaño de letra del CUERPO calculados a partir
            // de cuántos productos hay realmente -- pedido explícito del
            // usuario: el PDF SIEMPRE tiene que entrar en una sola hoja A4,
            // sin importar si el catálogo de despacho crece o se achica.
            // Presupuesto de ~600px de alto de página disponibles para la
            // tabla completa (A4 apaisado menos logo/título/meta y
            // márgenes), medido generando el PDF real -- se le resta lo que
            // ya ocupa el encabezado y se reparte el resto entre las filas
            // (+1 por la fila TOTAL), con topes para que ni se vea gigante
            // con pocos productos ni ilegible con muchos.
            $numFilasCuerpo = count($filas) + 1;
            $alturaCuerpoDisponible = 578 - $alturaHeader;
            $alturaFila = max(11, min(26, $alturaCuerpoDisponible / max(1, $numFilasCuerpo)));
            $fontFila = max(7, min(11, round($alturaFila * 0.42)));
            $padY = max(0.5, round(($alturaFila - $fontFila * 1.35) / 2, 1));
            $estiloCelda = "font-size:{$fontFila}px; padding:{$padY}px 2px;";
        @endphp
        <thead>
            <tr>
                <th class="producto" style="height: {{ $alturaHeader }}px; {{ $estiloCelda }}">Producto</th>
                <th class="sku" style="{{ $estiloCelda }}">SKU</th>
                @foreach($locales as $local)
                    <th class="local" style="height: {{ $alturaHeader }}px;"><span class="giro" style="width: {{ $anchoGiro($local->local_nombre) }}px; margin-left: -{{ $anchoGiro($local->local_nombre) / 2 }}px;">{{ $local->local_nombre }}</span></th>
                @endforeach
                <th class="local" style="height: {{ $alturaHeader }}px;"><span class="giro" style="width: {{ $anchoGiro('TOTAL') }}px; margin-left: -{{ $anchoGiro('TOTAL') / 2 }}px;">TOTAL</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($filas as $fila)
                <tr>
                    <td style="{{ $estiloCelda }}">{{ $fila['nombre'] }}</td>
                    <td style="{{ $estiloCelda }}">{{ $fila['codigo'] }}</td>
                    @foreach($fila['celdas'] as $celda)
                        <td class="num" style="{{ $estiloCelda }}">{{ number_format($celda['cantidad'], 0) }}</td>
                    @endforeach
                    <td class="num total" style="{{ $estiloCelda }}">{{ number_format($fila['total'], 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $locales->count() + 3 }}" style="{{ $estiloCelda }}">Sin sugerencias calculadas para esta fecha</td></tr>
            @endforelse
            @if($filas->isNotEmpty())
                <tr class="total">
                    <td colspan="2" style="{{ $estiloCelda }}">TOTAL</td>
                    @foreach($totalesColumna as $total)
                        <td class="num" style="{{ $estiloCelda }}">{{ number_format($total, 0) }}</td>
                    @endforeach
                    <td class="num" style="{{ $estiloCelda }}">{{ number_format($granTotal, 0) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
