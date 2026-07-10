<?php

use App\Models\SyncSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        // L'orario e' letto da SyncSetting ad ogni esecuzione di "schedule:run"
        // (un processo nuovo ogni minuto via cron, niente demone persistente):
        // cambiarlo in Impostazioni si riflette dal giro schedulato successivo,
        // senza toccare configurazione cron o riavviare nulla.
        //
        // "Europe/Rome" esplicito su entrambi i job: l'app salva tutto in UTC
        // (config('app.timezone'), corretto per i timestamp nel database), ma
        // l'orario scelto in Impostazioni e' pensato da un operatore in Italia
        // ("le 3 di notte" intende le 3 ora italiana, non UTC) - senza questo,
        // lo scheduler interpreterebbe "03:00" come UTC, cioe' le 5 (o le 4)
        // ora italiana a seconda dell'ora legale.
        $schedule->command('import:scheduled-run')
            ->dailyAt(SyncSetting::current()->scheduled_run_time)
            ->timezone('Europe/Rome')
            ->withoutOverlapping();

        $schedule->command('backup:run')
            ->dailyAt('03:30')
            ->timezone('Europe/Rome')
            ->withoutOverlapping();

        // Simula un worker persistente su hosting che non ne permette uno
        // (jailed, niente Supervisor): un giro corto ogni minuto che processa
        // tutto cio' che e' in coda (import, sync Shopify, rollback) e poi
        // esce da solo. "max-time" lo ferma comunque prima del giro successivo,
        // cosi' non si accumulano processi anche se una singola coda fosse lenta.
        $schedule->command('queue:work --stop-when-empty --tries=1 --max-time=50')
            ->everyMinute()
            ->withoutOverlapping();
    })->create();
