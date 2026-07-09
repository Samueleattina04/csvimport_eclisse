<?php

namespace App\Console\Commands;

use App\Jobs\RunImportJob;
use App\Models\ImportRun;
use App\Models\SyncSetting;
use Illuminate\Console\Command;

/**
 * Invocato dallo scheduler (bootstrap/app.php), mai a mano: a differenza di
 * "import:run" (pensato per un operatore al terminale, con --live/--sync
 * espliciti) questo comando legge la modalita' dry-run/live direttamente da
 * SyncSetting, cosi' cambiarla in Impostazioni si riflette sul prossimo giro
 * schedulato senza toccare codice o configurazione cron.
 */
class ScheduledImportCommand extends Command
{
    protected $signature = 'import:scheduled-run';

    protected $description = 'Avvia il sync notturno automatico secondo le Impostazioni (chiamato dallo scheduler)';

    public function handle(): int
    {
        $settings = SyncSetting::current();

        if (! $settings->auto_sync_enabled) {
            $this->info('Sync automatico disattivato nelle Impostazioni: nessuna azione.');

            return self::SUCCESS;
        }

        $running = ImportRun::query()
            ->whereIn('status', ['pending', 'downloading', 'parsing', 'diffing', 'syncing'])
            ->exists();

        if ($running) {
            $this->warn('C\'e\' gia\' un import in corso: salto questo giro schedulato.');

            return self::SUCCESS;
        }

        $run = ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'pending',
            'dry_run' => $settings->dry_run_mode,
        ]);

        RunImportJob::dispatch($run->id);

        $this->info("Import schedulato #{$run->id} accodato (dry_run=".($settings->dry_run_mode ? 'si' : 'no').').');

        return self::SUCCESS;
    }
}
