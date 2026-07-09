<?php

namespace App\Filament\Widgets;

use App\Models\ImportRun;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ImportStatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $running = ImportRun::query()
            ->whereIn('status', ['pending', 'downloading', 'parsing', 'diffing', 'syncing'])
            ->exists();

        $lastCompleted = ImportRun::query()
            ->whereIn('status', ['completed', 'completed_with_warnings'])
            ->latest('finished_at')
            ->first();

        $recentAnomalies = ImportRun::query()
            ->where('anomaly_detected', true)
            ->where('started_at', '>=', now()->subDays(7))
            ->count();

        return [
            Stat::make('Import in corso', $running ? 'Sì' : 'No')
                ->color($running ? 'info' : 'gray')
                ->icon($running ? 'heroicon-o-arrow-path' : 'heroicon-o-check-circle'),

            Stat::make('Ultimo import riuscito', $lastCompleted?->finished_at?->diffForHumans() ?? 'Mai')
                ->description($lastCompleted ? "{$lastCompleted->products_created} nuovi, {$lastCompleted->products_updated} aggiornati" : null)
                ->color('success'),

            Stat::make('Prodotti attivi su Shopify', Product::query()->where('is_active', true)->count())
                ->color('primary'),

            Stat::make('Anomalie (ultimi 7 giorni)', $recentAnomalies)
                ->color($recentAnomalies > 0 ? 'danger' : 'gray'),
        ];
    }
}
