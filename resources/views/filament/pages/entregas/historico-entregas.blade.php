<x-filament-panels::page>
    @php($promedio = $this->promedioPorLocalYDia())

    @if(! empty($promedio['locales']))
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Hora promedio de entrega por local × día de semana</x-slot>
            <x-slot name="description">Sobre todo el histórico. Formato HH:MM (N.º de entregas). Todavía NO alimenta la Directiva de Transferencia.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="p-2 text-left font-semibold">Local</th>
                            @foreach($promedio['dias'] as $dia)
                                <th class="p-2 text-center font-semibold">{{ \Illuminate\Support\Str::substr($dia, 0, 3) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($promedio['locales'] as $local)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="p-2 font-medium">{{ $local }}</td>
                                @foreach(array_keys($promedio['dias']) as $diaNum)
                                    <td class="p-2 text-center tabular-nums text-gray-600 dark:text-gray-400">
                                        {{ $promedio['celdas'][$local.'|'.$diaNum] ?? '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
