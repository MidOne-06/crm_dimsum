<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#111827}h1{font-size:16px;margin:0 0 5px}p{margin:0 0 12px;color:#4b5563;text-transform:capitalize}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d1d5db;padding:4px}th{background:#dce6f1;text-align:center;font-weight:bold}td.num{text-align:right}td.total,tr.total td{font-weight:bold;background:#dce6f1}
</style></head><body>
<h1>Directiva de Transferencia -- Cantidad sugerida</h1><p>Despacho: {{ $fecha }}</p>
<table><thead><tr><th>Producto</th><th>SKU</th>@foreach($locales as $local)<th>{{ $local->local_nombre }}</th>@endforeach<th>TOTAL</th></tr></thead><tbody>
@forelse($filas as $fila)<tr><td>{{ $fila['nombre'] }}</td><td>{{ $fila['codigo'] }}</td>@foreach($fila['cantidades'] as $cantidad)<td class="num">{{ number_format($cantidad, 0) }}</td>@endforeach<td class="num total">{{ number_format($fila['total'], 0) }}</td></tr>@empty<tr><td colspan="{{ $locales->count() + 3 }}">Sin sugerencias calculadas para esta fecha</td></tr>@endforelse
@if($filas->isNotEmpty())<tr class="total"><td colspan="2">TOTAL</td>@foreach($totalesColumna as $total)<td class="num">{{ number_format($total, 0) }}</td>@endforeach<td class="num">{{ number_format($granTotal, 0) }}</td></tr>@endif
</tbody></table></body></html>
