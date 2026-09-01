<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 18px 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 7px; color: #111827; }
        h1 { margin: 0 0 4px; font-size: 15px; }
        p { margin: 0 0 12px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #d1d5db; padding: 4px 3px; vertical-align: middle; }
        th { background: #e5e7eb; font-weight: bold; text-align: center; }
        th.local { writing-mode: vertical-rl; height: 88px; font-size: 6px; }
        td.number { text-align: right; }
        td.total { font-weight: bold; background: #f8fafc; }
        .code { width: 7%; } .item { width: 17%; } .unit { width: 6%; }
    </style>
</head>
<body>
    <h1>Reporte de requerimientos de stock</h1>
    <p>{{ $filters }} | Generado: {{ now()->format('d/m/Y H:i') }}</p>
    <table>
        <thead>
            <tr>
                <th class="code">Código</th>
                <th class="item">Producto</th>
                <th class="unit">Unidad</th>
                @foreach ($locals as $local)
                    <th class="local">{{ $local }}</th>
                @endforeach
                <th class="local">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->codigo }}</td>
                    <td>{{ $row->item }}</td>
                    <td>{{ $row->unidad }}</td>
                    @foreach ($locals as $index => $local)
                        <td class="number">{{ number_format((float) ($row->{'local_'.$index} ?? 0), 0) }}</td>
                    @endforeach
                    <td class="number total">{{ number_format((float) $row->cantidad_total, 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
