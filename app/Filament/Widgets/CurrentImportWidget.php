<?php

namespace App\Filament\Widgets;

use App\Models\ImportRun;
use Filament\Widgets\Widget;

class CurrentImportWidget extends Widget
{
    protected string $view = 'filament.widgets.current-import-widget';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function getPollingInterval(): ?string
    {
        return '3s';
    }

    public function getRun(): ?ImportRun
    {
        return ImportRun::query()
            ->whereIn('status', ['pending', 'downloading', 'parsing', 'diffing', 'syncing'])
            ->latest('id')
            ->first();
    }
}
