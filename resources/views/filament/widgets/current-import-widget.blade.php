@php
    $run = $this->getRun();
@endphp

<x-filament-widgets::widget>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6">
            @if ($run)
                @php
                    $progress = $run->syncProgress();
                    $statusLabels = [
                        'pending' => 'In attesa di avvio',
                        'downloading' => 'Download del CSV in corso',
                        'parsing' => 'Analisi del CSV in corso',
                        'diffing' => 'Calcolo delle differenze in corso',
                        'syncing' => 'Sincronizzazione con Shopify in corso',
                    ];
                @endphp
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        Import #{{ $run->id }} in corso
                    </h3>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $statusLabels[$run->status] ?? $run->status }}
                    </span>
                </div>

                @if ($progress)
                    <div class="mt-4">
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-300">
                            <span>{{ $progress['processed'] }} / {{ $progress['total'] }} prodotti</span>
                            <span>{{ $progress['percent'] }}%</span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                class="h-2 rounded-full bg-primary-600 transition-all"
                                style="width: {{ $progress['percent'] }}%"
                            ></div>
                        </div>
                        @if ($progress['failed'] > 0)
                            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">
                                {{ $progress['failed'] }} prodotti falliti finora.
                            </p>
                        @endif
                    </div>
                @else
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        Preparazione dell'import in corso, avanzamento non ancora disponibile.
                    </p>
                @endif
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nessun import in corso al momento.
                </p>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
