<?php

namespace App\Console\Commands;

use App\Jobs\RunImportJob;
use App\Models\ImportRun;
use Illuminate\Console\Command;

class RunImportCommand extends Command
{
    protected $signature = 'import:run
        {--sync : Esegui subito nel processo corrente invece di accodare il job}
        {--live : Applica davvero le modifiche a Shopify. Senza questo flag il run e\' sempre dry-run: calcola e mostra il piano senza chiamare nessuna API}';

    protected $description = 'Avvia manualmente fetch + staging + diff (+ sync Shopify se --live) del CSV Eclisse';

    public function handle(): int
    {
        $running = ImportRun::query()
            ->whereIn('status', ['pending', 'downloading', 'parsing', 'diffing', 'syncing'])
            ->exists();

        if ($running) {
            $this->error('C\'e\' gia\' un import in corso: attendi che finisca prima di lanciarne un altro.');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');

        if ($live) {
            $this->warn('Modalita\' LIVE richiesta: al momento non e\' ancora implementata la sync reale verso Shopify, il run terminera\' con un errore esplicito.');
        } else {
            $this->info('Modalita\' dry-run (default): nessuna chiamata a Shopify verra\' effettuata.');
        }

        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'pending',
            'dry_run' => ! $live,
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

        return in_array($run->status, ['failed'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function reportResult(ImportRun $run): void
    {
        $this->line('');
        $this->line("Stato finale: <fg=yellow>{$run->status}</>");
        $this->line("Righe CSV lette: {$run->csv_row_count}");
        $this->line("Prodotti trovati: {$run->csv_product_count}");
        $this->line("Prodotti falliti in fase di parsing: {$run->products_failed}");

        if ($run->anomaly_detected) {
            $this->error("Anomalia rilevata: {$run->anomaly_reason}");
        }

        if ($run->error_message) {
            $this->error("Errore: {$run->error_message}");
        }

        if (in_array($run->status, ['completed', 'completed_with_warnings'], true)) {
            $this->line('');
            $this->line($run->dry_run ? 'Piano dry-run (nessuna chiamata a Shopify effettuata):' : 'Sync applicata a Shopify:');
            $this->line("  Prodotti nuovi: {$run->products_created}");
            $this->line("  Prodotti aggiornati: {$run->products_updated}");
            $this->line("  Prodotti invariati: {$run->products_unchanged}");
            $this->line("  Prodotti spariti dal CSV: {$run->products_removed}");
            $this->line("  Varianti nuove/aggiornate/rimosse: {$run->variants_created}/{$run->variants_updated}/{$run->variants_removed}");
        }

        $warnings = $run->logs()->where('level', 'warning')->count();
        $errors = $run->logs()->where('level', 'error')->count();
        $this->line('');
        $this->line("Log: {$warnings} warning, {$errors} error (dettaglio nella tabella import_logs, filtrabile per import_run_id={$run->id}).");
    }
}
