<?php

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunRollbackJob;
use App\Models\ImportRun;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImportRun extends ViewRecord
{
    protected static string $resource = ImportRunResource::class;

    public function getTitle(): string
    {
        return "Import #{$this->record->id}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rollback')
                ->label('Rollback a questo import')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (ImportRun $record) => $record->isSuccessful()
                    && ! $record->dry_run
                    && $record->snapshotProducts()->exists())
                ->schema([
                    Toggle::make('live')
                        ->label('Applica davvero il rollback a Shopify')
                        ->helperText('Se disattivo (default): calcola solo il piano per tornare a questo stato, nessuna chiamata a Shopify.')
                        ->default(false),
                ])
                ->requiresConfirmation()
                ->modalDescription('Riporta il catalogo canonico allo stato registrato subito dopo questo import: i prodotti apparsi dopo verranno nascosti (mai eliminati), quelli cambiati verranno ripristinati.')
                ->action(function (ImportRun $record, array $data) {
                    $running = ImportRun::query()
                        ->whereIn('status', ['pending', 'downloading', 'parsing', 'diffing', 'syncing'])
                        ->exists();

                    if ($running) {
                        Notification::make()
                            ->title('C\'e\' gia\' un import in corso')
                            ->body('Attendi che finisca prima di lanciare un rollback.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $rollbackRun = ImportRun::create([
                        'trigger_type' => 'rollback',
                        'status' => 'pending',
                        'dry_run' => ! ($data['live'] ?? false),
                        'rollback_of_import_run_id' => $record->id,
                    ]);

                    RunRollbackJob::dispatch($rollbackRun->id, $record->id);

                    Notification::make()
                        ->title("Rollback #{$rollbackRun->id} avviato")
                        ->body("Riporta il catalogo allo stato dell'import #{$record->id}.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
