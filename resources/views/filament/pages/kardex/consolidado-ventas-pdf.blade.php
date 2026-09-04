<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 18px 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111827; }
        h1 { margin: 0 0 4px; font-size: 15px; }
        p { margin: 0 0 12px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 4px 5px; vertical-align: middle; }
        th { background: #e5e7eb; font-weight: bold; text-align: center; }
        td.number { text-align: right; }
        td.total { font-weight: bold; background: #f8fafc; }
        .code { width: 9%; } .item { width: 30%; } .unit { width: 8%; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <p>{{ $subtitulo }} | Generado: {{ now()->format('d/m/Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th class="code">Código</th>
                <th class="item">Producto</th>
                <th class="unit">Unidad</th>
                @foreach ($columnas as $columna)
                    <th>{{ $columna['label'] }}</th>
                @endforeach
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr>
                    <td>{{ $fila->cod_interno }}</td>
                    <td>{{ $fila->item_nombre }}</td>
                    <td>{{ $fila->unidad }}</td>
                    @php($total = 0)
                    @foreach ($columnas as $columna)
                        @php($valor = (float) ($fila->{$columna['alias']} ?? 0))
                        @php($total += $valor)
                        <td class="number">{{ number_format($valor, 0) }}</td>
                    @endforeach
                    <td class="number total">{{ number_format($total, 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
