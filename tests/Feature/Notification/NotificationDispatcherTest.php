<?php

namespace Tests\Feature\Notification;

use App\Mail\ImportRunReportMail;
use App\Models\ImportRun;
use App\Models\SyncSetting;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_invia_email_e_slack_su_fallimento_quando_configurato(): void
    {
        Mail::fake();
        Http::fake();

        SyncSetting::current()->update([
            'notification_email' => 'ops@eclisse.moda',
            'slack_webhook_url' => 'https://hooks.slack.test/webhook',
            'notify_on_failure' => true,
            'notify_on_success' => false,
        ]);

        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'failed',
            'dry_run' => false,
            'error_message' => 'Download CSV fallito',
        ]);

        (new NotificationDispatcher)->notify($run);

        Mail::assertSent(ImportRunReportMail::class, fn (ImportRunReportMail $mail) => $mail->hasTo('ops@eclisse.moda') && $mail->category === 'failure');
        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/webhook');
    }

    public function test_non_invia_nulla_se_i_flag_sono_disattivi(): void
    {
        Mail::fake();
        Http::fake();

        SyncSetting::current()->update([
            'notification_email' => 'ops@eclisse.moda',
            'slack_webhook_url' => 'https://hooks.slack.test/webhook',
            'notify_on_success' => false,
            'notify_on_failure' => false,
            'notify_on_anomaly' => false,
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        (new NotificationDispatcher)->notify($run);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_non_invia_nulla_se_email_e_slack_non_sono_configurati(): void
    {
        Mail::fake();
        Http::fake();

        SyncSetting::current()->update([
            'notify_on_success' => true,
        ]);

        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'completed', 'dry_run' => false]);

        (new NotificationDispatcher)->notify($run);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_invia_notifica_anomalia_indipendentemente_dallo_stato(): void
    {
        Mail::fake();

        SyncSetting::current()->update([
            'notification_email' => 'ops@eclisse.moda',
            'notify_on_anomaly' => true,
            'notify_on_failure' => false,
        ]);

        $run = ImportRun::create([
            'trigger_type' => 'scheduled',
            'status' => 'failed',
            'dry_run' => true,
            'anomaly_detected' => true,
            'anomaly_reason' => 'Crollo prodotti rilevato',
        ]);

        (new NotificationDispatcher)->notify($run);

        Mail::assertSent(ImportRunReportMail::class, fn (ImportRunReportMail $mail) => $mail->category === 'anomaly');
        // notify_on_failure e' disattivo: non deve arrivare anche la mail "failure".
        Mail::assertSentCount(1);
    }

    public function test_invia_notifica_di_successo(): void
    {
        Mail::fake();

        SyncSetting::current()->update([
            'notification_email' => 'ops@eclisse.moda',
            'notify_on_success' => true,
        ]);

        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'completed',
            'dry_run' => false,
            'products_created' => 2,
        ]);

        (new NotificationDispatcher)->notify($run);

        Mail::assertSent(ImportRunReportMail::class, fn (ImportRunReportMail $mail) => $mail->category === 'success');
    }
}
