<?php

namespace App\Services\Notification;

use App\Mail\ImportRunReportMail;
use App\Models\ImportRun;
use App\Models\SyncSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Invia email/Slack a fine run in base ai flag configurati in SyncSetting.
 * Best-effort di proposito: un webhook Slack rotto o un SMTP giu' non deve
 * mai far fallire la pipeline di import, quindi ogni invio e' isolato in un
 * try/catch che al massimo scrive nei log applicativi.
 */
class NotificationDispatcher
{
    public function notify(ImportRun $importRun): void
    {
        $settings = SyncSetting::current();

        if ($importRun->anomaly_detected && $settings->notify_on_anomaly) {
            $this->dispatch($settings, $importRun, 'anomaly');
        }

        $category = $this->category($importRun);

        if ($category === 'failure' && $settings->notify_on_failure) {
            $this->dispatch($settings, $importRun, 'failure');
        } elseif ($category === 'success' && $settings->notify_on_success) {
            $this->dispatch($settings, $importRun, 'success');
        }
    }

    /**
     * "completed_with_warnings" da solo NON basta per contare come fallimento:
     * sui dati reali del gestionale, avvisi come "EAN duplicato" o "quantita'
     * negativa" sono normalissimi e compaiono praticamente ogni notte, gia'
     * gestiti in automatico dal parser. Trattarli come "fallimento" avrebbe
     * significato un'email "Import con errori" ogni sera anche quando va
     * tutto bene, con l'effetto di farla ignorare proprio nella notte in cui
     * serve davvero. Conta come fallimento solo un run "failed", prodotti
     * effettivamente falliti in sync, o righe scartate (livello "error", non
     * "warning") durante il parsing.
     */
    private function category(ImportRun $importRun): ?string
    {
        if ($importRun->status === 'failed') {
            return 'failure';
        }

        if (! in_array($importRun->status, ['completed', 'completed_with_warnings'], true)) {
            return null;
        }

        $hasRealFailure = $importRun->products_failed > 0
            || $importRun->logs()->where('level', 'error')->exists();

        return $hasRealFailure ? 'failure' : 'success';
    }

    private function dispatch(SyncSetting $settings, ImportRun $importRun, string $category): void
    {
        if (filled($settings->notification_email)) {
            $this->sendMail($settings->notification_email, $importRun, $category);
        }

        if (filled($settings->slack_webhook_url)) {
            $this->sendSlack($settings->slack_webhook_url, $importRun, $category);
        }
    }

    private function sendMail(string $email, ImportRun $importRun, string $category): void
    {
        try {
            Mail::to($email)->send(new ImportRunReportMail($importRun, $category));
        } catch (Throwable $e) {
            Log::error('Invio email di notifica import fallito', [
                'import_run_id' => $importRun->id,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendSlack(string $webhookUrl, ImportRun $importRun, string $category): void
    {
        try {
            Http::timeout(10)->post($webhookUrl, [
                'text' => $this->slackText($importRun, $category),
            ]);
        } catch (Throwable $e) {
            Log::error('Invio notifica Slack import fallito', [
                'import_run_id' => $importRun->id,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function slackText(ImportRun $importRun, string $category): string
    {
        $emoji = match ($category) {
            'anomaly' => ':rotating_light:',
            'failure' => ':x:',
            default => ':white_check_mark:',
        };

        $summary = match ($category) {
            'anomaly' => "Anomalia rilevata: {$importRun->anomaly_reason}",
            'failure' => $this->failureSummary($importRun),
            default => sprintf(
                '%d nuovi, %d aggiornati, %d rimossi.',
                $importRun->products_created,
                $importRun->products_updated,
                $importRun->products_removed,
            ),
        };

        $mode = $importRun->dry_run ? 'dry-run' : 'live';

        return "{$emoji} Import #{$importRun->id} ({$mode}): {$summary}";
    }

    private function failureSummary(ImportRun $importRun): string
    {
        if ($importRun->status === 'failed') {
            return $importRun->error_message ?? 'Import fallito.';
        }

        if ($importRun->products_failed > 0) {
            return "{$importRun->products_failed} prodotti falliti su questo import.";
        }

        $errorRows = $importRun->logs()->where('level', 'error')->count();

        return "{$errorRows} righe scartate durante l'analisi del CSV.";
    }
}
