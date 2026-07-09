<?php

namespace App\Filament\Resources\ImportRuns\Tables;

use App\Jobs\RunImportJob;
use App\Models\ImportRun;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('3s')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('trigger_type')
                    ->label('Origine')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'scheduled' => 'Schedulato',
                        'manual' => 'Manuale',
                        'rollback' => 'Rollback',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'scheduled' => 'info',
                        'manual' => 'gray',
                        'rollback' => 'warning',
                        default => 'gray',
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
                TextColumn::make('sync_progress')
                    ->label('Avanzamento')
                    ->state(function (ImportRun $record) {
                        if ($record->status !== 'syncing') {
                            return null;
                        }
                        $progress = $record->syncProgress();

                        return $progress ? "{$progress['processed']}/{$progress['total']} prodotti ({$progress['percent']}%)" : 'Avvio...';
                    })
                    ->placeholder('—'),
                IconColumn::make('dry_run')
                    ->label('Dry-run')
                    ->boolean(),
                IconColumn::make('anomaly_detected')
                    ->label('Anomalia')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
                TextColumn::make('products_created')->label('Nuovi')->alignCenter(),
                TextColumn::make('products_updated')->label('Aggiornati')->alignCenter(),
                TextColumn::make('products_unchanged')->label('Invariati')->alignCenter(),
                TextColumn::make('products_removed')->label('Rimossi')->alignCenter(),
                TextColumn::make('products_failed')->label('Falliti')->alignCenter()
                    ->color(fn (?int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('started_at')
                    ->label('Iniziato')
                    ->since()
                    ->sortable()
                    ->dateTimeTooltip(),
                TextColumn::make('duration_seconds')
                    ->label('Durata')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? gmdate('H:i:s', $state) : '—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'downloading' => 'Download CSV',
                        'parsing' => 'Analisi CSV',
                        'diffing' => 'Calcolo differenze',
                        'syncing' => 'Sync Shopify',
                        'completed' => 'Completato',
                        'completed_with_warnings' => 'Completato con avvisi',
                        'failed' => 'Fallito',
                    ]),
                SelectFilter::make('trigger_type')
                    ->label('Origine')
                    ->options([
                        'scheduled' => 'Schedulato',
                        'manual' => 'Manuale',
                        'rollback' => 'Rollback',
                    ]),
                TernaryFilter::make('dry_run')->label('Dry-run'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->headerActions([
                Action::make('importaOra')
                    ->label('Importa ora')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->schema([
                        Toggle::make('live')
                            ->label('Applica davvero le modifiche a Shopify')
                            ->helperText('Se disattivo (default): calcola solo il piano, nessuna chiamata a Shopify.')
                            ->default(false),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Per default viene eseguito un dry-run: nessuna modifica reale a Shopify. Attiva l\'interruttore per applicare davvero le modifiche.')
                    ->action(function (array $data) {
                        $running = ImportRun::query()
                            ->whereIn('status', ['pending', 'downloading', 'parsing', 'diffing', 'syncing'])
                            ->exists();

                        if ($running) {
                            Notification::make()
                                ->title('C\'e\' gia\' un import in corso')
                                ->body('Attendi che finisca prima di lanciarne un altro.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $run = ImportRun::create([
                            'trigger_type' => 'manual',
                            'status' => 'pending',
                            'dry_run' => ! ($data['live'] ?? false),
                        ]);

                        RunImportJob::dispatch($run->id);

                        Notification::make()
                            ->title("Import #{$run->id} avviato")
                            ->body('Segui l\'avanzamento in questa pagina.')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
