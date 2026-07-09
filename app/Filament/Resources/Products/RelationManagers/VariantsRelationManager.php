<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Varianti';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('codice_ean')
            ->defaultSort('position')
            ->columns([
                ImageColumn::make('image_url')->label('')->square(),
                TextColumn::make('codice_ean')->label('EAN')->searchable()->placeholder('—'),
                TextColumn::make('color')->label('Colore')->placeholder('—'),
                TextColumn::make('size')->label('Taglia')->placeholder('—'),
                TextColumn::make('length')->label('Lunghezza')->placeholder('—'),
                TextColumn::make('price')->label('Prezzo')->money('EUR'),
                TextColumn::make('quantity')->label('Quantità')->alignCenter(),
                IconColumn::make('is_active')->label('Attiva')->boolean(),
                TextColumn::make('shopify_variant_id')->label('ID variante Shopify')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_synced_at')->label('Ultimo sync')->since()->dateTimeTooltip()->placeholder('—'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
