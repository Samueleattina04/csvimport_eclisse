<?php

namespace App\Console\Commands;

use App\Jobs\RunImportJob;
use App\Models\ImportRun;
use Illuminate\Console\Command;

class RunImportCommand extends Command
{
    protected $signature = 'import:run {--sync : Esegui subito nel processo corrente invece di accodare il job}';

    protected $description = 'Avvia manualmente il download e il parsing del CSV Eclisse (fase attuale: fetch + staging, senza ancora diff/Shopify)';

    public function handle(): int
    {
        $running = ImportRun::query()
            ->whereIn('status', ['pending', 'downloading', 'parsing', 'diffing', 'syncing'])
            ->exists();

        if ($running) {
            $this->error('C\'e\' gia\' un import in corso: attendi che finisca prima di lanciarne un altro.');

            return self::FAILURE;
        }

        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'pending',
            'initiated_by_user_id' => null,
        ]);

        $this->info("Import #{$run->id} ({$run->uuid}) creato.");

        if ($this->option('sync')) {
            RunImportJob::dispatchSync($run->id);
        } else {
            RunImportJob::dispatch($run->id);
            $this->info('Job accodato. Assicurati che un worker sia attivo (php artisan queue:work) oppure lancia con --sync per eseguirlo subito.');

            return self::SUCCESS;
        }

        $run->refresh();
        $this->reportResult($run);

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function reportResult(ImportRun $run): void
    {
        $this->line('');
        $this->line("Stato finale: <fg=yellow>{$run->status}</>");
        $this->line("Righe CSV lette: {$run->csv_row_count}");
        $this->line("Prodotti trovati: {$run->csv_product_count}");
        $this->line("Prodotti falliti: {$run->products_failed}");

        if ($run->anomaly_detected) {
            $this->error("Anomalia rilevata: {$run->anomaly_reason}");
        }

        if ($run->error_message) {
            $this->error("Errore: {$run->error_message}");
        }

        $warnings = $run->logs()->where('level', 'warning')->count();
        $errors = $run->logs()->where('level', 'error')->count();
        $this->line("Log: {$warnings} warning, {$errors} error (dettaglio nella tabella import_logs).");
    }
}
