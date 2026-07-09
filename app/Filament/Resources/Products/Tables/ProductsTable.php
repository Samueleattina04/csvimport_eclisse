<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                ImageColumn::make('main_image_url')
                    ->label('')
                    ->square(),
                TextColumn::make('codice_articolo')
                    ->label('Codice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Titolo')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('vendor')
                    ->label('Marca')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category_path')
                    ->label('Categoria')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Stato Shopify')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'published' => 'Pubblicato',
                        'draft' => 'Bozza',
                        default => $state,
                    })
                    ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('is_active')
                    ->label('Attivo')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Sì' : 'Rimosso')
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                TextColumn::make('variants_count')
                    ->label('Varianti')
                    ->counts('variants')
                    ->alignCenter(),
                TextColumn::make('last_synced_at')
                    ->label('Ultimo sync')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato Shopify')
                    ->options([
                        'published' => 'Pubblicato',
                        'draft' => 'Bozza',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Attivo')
                    ->trueLabel('Solo attivi')
                    ->falseLabel('Solo rimossi'),
                SelectFilter::make('vendor')
                    ->label('Marca')
                    ->options(fn () => Product::query()
                        ->whereNotNull('vendor')
                        ->distinct()
                        ->orderBy('vendor')
                        ->pluck('vendor', 'vendor')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
