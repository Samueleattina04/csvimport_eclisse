<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Prodotto')
                    ->columns(4)
                    ->schema([
                        ImageEntry::make('main_image_url')
                            ->label('Immagine')
                            ->columnSpan(1),
                        TextEntry::make('title')->label('Titolo')->columnSpan(3),
                        TextEntry::make('codice_articolo')->label('Codice articolo'),
                        TextEntry::make('vendor')->label('Marca')->placeholder('—'),
                        TextEntry::make('gender')->label('Genere')->placeholder('—'),
                        TextEntry::make('category_path')->label('Categoria')->placeholder('—'),
                        TextEntry::make('collection_name')->label('Collezione')->placeholder('—'),
                        TextEntry::make('handle')->label('Handle Shopify'),
                        TextEntry::make('shopify_product_id')->label('ID prodotto Shopify')->placeholder('—')->copyable(),
                        TextEntry::make('status')
                            ->label('Stato Shopify')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => match ($state) {
                                'published' => 'Pubblicato',
                                'draft' => 'Bozza',
                                default => $state,
                            })
                            ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
                        IconEntry::make('is_active')->label('Attivo su Shopify')->boolean(),
                        TextEntry::make('lastSeenImportRun.id')->label('Ultimo import in cui e\' apparso')->placeholder('—'),
                        TextEntry::make('last_synced_at')->label('Ultimo sync')->dateTime()->placeholder('—'),
                        TextEntry::make('body_html')
                            ->label('Descrizione')
                            ->html()
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
