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
        .code { width: 9%; } .item { width: 33%; }
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
                @foreach ($columnas as $columna)
                    <th>{{ $columna['label'] }}</th>
                @endforeach
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr>
                    <td>{{ $fila['cod_interno'] }}</td>
                    <td>{{ $fila['item_nombre'] }}</td>
                    @foreach ($columnas as $columna)
                        <td class="number">{{ number_format((float) ($fila[$columna['alias']] ?? 0), 0) }}</td>
                    @endforeach
                    <td class="number total">{{ number_format((float) $fila['total'], 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
