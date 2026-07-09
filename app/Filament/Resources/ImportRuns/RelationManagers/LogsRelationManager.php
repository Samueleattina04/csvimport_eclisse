<?php

namespace App\Filament\Resources\ImportRuns\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Log leggibile riga per riga di un import: cosa e' cambiato (diff) ed eventuali
 * errori/warning del parsing, filtrabile per livello e cercabile per codice
 * articolo (esattamente il requisito "log leggibile degli errori riga per riga").
 */
class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Log';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('message')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('level')
                    ->label('Livello')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'info' => 'Info',
                        'warning' => 'Warning',
                        'error' => 'Errore',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('codice_articolo')
                    ->label('Codice articolo')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('codice_ean')
                    ->label('EAN')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('csv_row_number')
                    ->label('Riga CSV')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('message')
                    ->label('Messaggio')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->label('Livello')
                    ->options([
                        'info' => 'Info',
                        'warning' => 'Warning',
                        'error' => 'Errore',
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
