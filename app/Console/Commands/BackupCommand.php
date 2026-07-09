<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Dump del database -> gzip -> upload su Google Drive via rclone, pensato per
 * girare da cron su hosting condiviso senza demoni persistenti (stesso
 * vincolo del sync notturno). Non tiene copie locali dopo un upload riuscito:
 * lo storage su hosting condiviso e' tipicamente a quota stretta, la copia
 * remota e' l'unica che conta. La password MySQL passa via variabile
 * d'ambiente (MYSQL_PWD), mai negli argomenti del comando: altrimenti
 * sarebbe visibile a chiunque sulla stessa macchina esegua "ps".
 */
class BackupCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Esegue un dump del database, lo comprime e lo carica su Google Drive via rclone';

    public function handle(): int
    {
        $remote = config('services.backup.rclone_remote');

        if (blank($remote)) {
            $this->error('BACKUP_RCLONE_REMOTE non configurato: nessun backup effettuato.');

            return self::FAILURE;
        }

        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory);

        $filename = 'eclisse-sync-'.now()->format('Y-m-d-His').'.sql.gz';
        $dumpPath = "{$directory}/{$filename}";

        if (! $this->dumpDatabase($dumpPath)) {
            return self::FAILURE;
        }

        if (! $this->uploadToRemote($dumpPath, $remote)) {
            return self::FAILURE;
        }

        File::delete($dumpPath);

        $this->pruneRemoteBackups($remote);

        $this->info("Backup {$filename} caricato su {$remote}.");

        return self::SUCCESS;
    }

    private function dumpDatabase(string $dumpPath): bool
    {
        // Sempre la connessione "mysql" esplicitamente, non "database.default":
        // il backup riguarda il database di produzione dell'app, indipendentemente
        // da quale connessione sia quella di default nell'ambiente corrente (es.
        // sqlite nei test).
        $db = config('database.connections.mysql');
        $mysqldump = config('services.backup.mysqldump_binary');

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --single-transaction --quick %s | gzip -c > %s',
            escapeshellcmd($mysqldump),
            escapeshellarg((string) $db['host']),
            escapeshellarg((string) $db['port']),
            escapeshellarg((string) $db['username']),
            escapeshellarg((string) $db['database']),
            escapeshellarg($dumpPath),
        );

        $result = Process::timeout(300)
            ->env(['MYSQL_PWD' => (string) $db['password']])
            ->run($command);

        if ($result->failed()) {
            $this->error('mysqldump fallito: '.$result->errorOutput());

            return false;
        }

        return true;
    }

    private function uploadToRemote(string $dumpPath, string $remote): bool
    {
        $rclone = config('services.backup.rclone_binary');

        $result = Process::timeout(300)->run([$rclone, 'copy', $dumpPath, $remote]);

        if ($result->failed()) {
            $this->error('Upload rclone fallito: '.$result->errorOutput());

            return false;
        }

        return true;
    }

    /**
     * Non fatale di proposito: se la pulizia fallisce il backup appena fatto
     * resta comunque caricato con successo, quindi si segnala ma non si fa
     * fallire l'intero comando per questo.
     */
    private function pruneRemoteBackups(string $remote): void
    {
        $rclone = config('services.backup.rclone_binary');
        $retentionDays = config('services.backup.retention_days');

        $result = Process::timeout(300)->run([
            $rclone, 'delete', $remote, '--min-age', "{$retentionDays}d",
        ]);

        if ($result->failed()) {
            $this->warn('Pulizia dei backup vecchi fallita: '.$result->errorOutput());
        }
    }
}
