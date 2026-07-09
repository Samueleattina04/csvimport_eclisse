<?php

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewImportRun extends ViewRecord
{
    protected static string $resource = ImportRunResource::class;

    public function getTitle(): string
    {
        return "Import #{$this->record->id}";
    }
}
