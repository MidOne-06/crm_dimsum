<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#111827}h1{font-size:16px;margin:0 0 5px}p{margin:0 0 12px;color:#4b5563}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d1d5db;padding:4px}th{background:#e5e7eb;text-align:center;font-weight:bold}td.num{text-align:right}td.total{font-weight:bold}
</style></head><body>
<h1>Reporte de movimientos entre almacenes</h1><p>{{ $filters }}</p>
<table><thead><tr><th>Código</th><th>Producto</th><th>Unidad</th>@foreach($locals as $local)<th>{{ $local }}</th>@endforeach<th>TOTAL</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->codigo }}</td><td>{{ $row->item }}</td><td>{{ $row->unidad }}</td>@foreach($locals as $index => $_)<td class="num">{{ number_format((float) ($row->{'local_'.$index} ?? 0), $decimals) }}</td>@endforeach<td class="num total">{{ number_format((float) $row->cantidad_total, $decimals) }}</td></tr>@empty<tr><td colspan="{{ count($locals) + 4 }}">Sin registros</td></tr>@endforelse
</tbody></table></body></html>
