<?php

namespace App\Filament\Widgets;

use App\Models\ImportRun;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentImportsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Import recenti')
            ->query(fn (): Builder => ImportRun::query()->latest('id'))
            ->defaultPaginationPageOption(5)
            ->poll('5s')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('trigger_type')
                    ->label('Origine')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'scheduled' => 'Schedulato',
                        'manual' => 'Manuale',
                        'rollback' => 'Rollback',
                        default => $state,
                    }),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => 'In attesa',
                        'downloading' => 'Download CSV',
                        'parsing' => 'Analisi CSV',
                        'diffing' => 'Calcolo differenze',
                        'syncing' => 'Sync Shopify',
                        'completed' => 'Completato',
                        'completed_with_warnings' => 'Completato con avvisi',
                        'failed' => 'Fallito',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'completed_with_warnings' => 'warning',
                        'failed' => 'danger',
                        'pending', 'downloading', 'parsing', 'diffing', 'syncing' => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('dry_run')->label('Dry-run')->boolean(),
                TextColumn::make('products_updated')->label('Aggiornati')->alignCenter(),
                TextColumn::make('products_failed')->label('Falliti')->alignCenter()
                    ->color(fn (?int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('started_at')->label('Iniziato')->since()->dateTimeTooltip(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (ImportRun $record) => route('filament.admin.resources.import-runs.view', $record)),
            ]);
    }
}
