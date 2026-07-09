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
        $schedule->command('import:scheduled-run')
            ->dailyAt(SyncSetting::current()->scheduled_run_time)
            ->withoutOverlapping();

        $schedule->command('backup:run')
            ->dailyAt('03:30')
            ->withoutOverlapping();
    })->create();
