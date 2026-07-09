<?php

namespace App\Filament\Resources\ImportRuns\Schemas;

use App\Models\ImportRun;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Riepilogo')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Stato')
                            ->badge()
                            ->columnSpan(2)
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
                                default => 'info',
                            }),
                        TextEntry::make('trigger_type')
                            ->label('Origine')
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'scheduled' => 'Schedulato',
                                'manual' => 'Manuale',
                                'rollback' => 'Rollback',
                                default => $state,
                            }),
                        TextEntry::make('dry_run')
                            ->label('Modalita\'')
                            ->formatStateUsing(fn (bool $state) => $state ? 'Dry-run' : 'Live'),
                        TextEntry::make('sync_progress')
                            ->label('Avanzamento')
                            ->state(fn (ImportRun $record) => $record->status === 'syncing'
                                ? ($record->syncProgress()['percent'] ?? 0).'%'
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('started_at')->label('Iniziato')->dateTime(),
                        TextEntry::make('finished_at')->label('Terminato')->dateTime()->placeholder('—'),
                        TextEntry::make('duration_seconds')
                            ->label('Durata')
                            ->formatStateUsing(fn (?int $state) => $state !== null ? gmdate('H:i:s', $state) : '—'),
                        TextEntry::make('source_url')->label('URL sorgente')->placeholder('—')->columnSpan(2),
                    ]),

                Section::make('Anomalie ed errori')
                    ->visible(fn (ImportRun $record) => $record->anomaly_detected || $record->error_message)
                    ->schema([
                        IconEntry::make('anomaly_detected')->label('Anomalia rilevata')->boolean(),
                        TextEntry::make('anomaly_reason')->label('Motivo anomalia')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('error_message')->label('Errore')->placeholder('—')->columnSpanFull()->color('danger'),
                    ]),

                Section::make('Dati CSV')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('csv_row_count')->label('Righe lette'),
                        TextEntry::make('csv_product_count')->label('Prodotti trovati'),
                        TextEntry::make('previous_successful_product_count')->label('Prodotti (ultimo import riuscito)')->placeholder('—'),
                        TextEntry::make('csv_sha256')->label('SHA-256 del file')->placeholder('—')->columnSpanFull()->copyable(),
                    ]),

                Section::make('Prodotti')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('products_created')->label('Nuovi')->badge()->color('success'),
                        TextEntry::make('products_updated')->label('Aggiornati')->badge()->color('info'),
                        TextEntry::make('products_unchanged')->label('Invariati')->badge()->color('gray'),
                        TextEntry::make('products_removed')->label('Rimossi')->badge()->color('warning'),
                        TextEntry::make('products_failed')->label('Falliti')->badge()->color('danger'),
                    ]),

                Section::make('Varianti')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('variants_created')->label('Nuove')->badge()->color('success'),
                        TextEntry::make('variants_updated')->label('Aggiornate')->badge()->color('info'),
                        TextEntry::make('variants_removed')->label('Rimosse')->badge()->color('warning'),
                    ]),
            ]);
    }
}
