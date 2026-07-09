<?php

namespace App\Filament\Resources\ImportRuns;

use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\LogsRelationManager;
use App\Filament\Resources\ImportRuns\Schemas\ImportRunInfolist;
use App\Filament\Resources\ImportRuns\Tables\ImportRunsTable;
use App\Models\ImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Solo lettura di proposito: i run vengono creati dalla pipeline (schedulata o
 * dal pulsante "Importa ora"), mai a mano da un form, quindi niente pagine di
 * creazione/modifica.
 */
class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Import';

    protected static ?string $modelLabel = 'import';

    protected static ?string $pluralModelLabel = 'import';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return ImportRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'view' => ViewImportRun::route('/{record}'),
        ];
    }
}
