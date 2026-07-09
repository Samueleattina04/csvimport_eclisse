@php
    $run = $importRun;
    $statusLabels = [
        'completed' => 'Completato',
        'completed_with_warnings' => 'Completato con avvisi',
        'failed' => 'Fallito',
    ];
@endphp
<p>
    Import #{{ $run->id }} ({{ $run->dry_run ? 'dry-run' : 'live' }}, origine {{ $run->trigger_type }})
    concluso con stato: <strong>{{ $statusLabels[$run->status] ?? $run->status }}</strong>.
</p>

@if ($run->anomaly_detected)
    <p><strong>Anomalia rilevata:</strong> {{ $run->anomaly_reason }}</p>
@endif

@if ($run->error_message)
    <p><strong>Errore:</strong> {{ $run->error_message }}</p>
@endif

<p>
    Prodotti: {{ $run->products_created }} nuovi, {{ $run->products_updated }} aggiornati,
    {{ $run->products_unchanged }} invariati, {{ $run->products_removed }} rimossi,
    {{ $run->products_failed }} falliti.
</p>

<p>
    <a href="{{ url("/admin/import-runs/{$run->id}") }}">Vedi i dettagli nella dashboard</a>
</p>
