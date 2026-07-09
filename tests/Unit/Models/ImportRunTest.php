<?php

namespace Tests\Unit\Models;

use App\Models\ImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ImportRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_seconds_since_start_e_un_intero_non_negativo(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $run = ImportRun::create([
            'trigger_type' => 'manual',
            'status' => 'parsing',
            'started_at' => now(),
        ]);

        // Carbon 3 ha cambiato il default di diffInSeconds() da assoluto a
        // firmato: senza "absolute: true" esplicito, now()->diffInSeconds()
        // puo' restituire un float negativo, che una colonna unsignedInteger
        // rifiuta (regressione reale, vista contro dati di produzione).
        Carbon::setTestNow('2026-01-01 10:00:05');

        $seconds = $run->secondsSinceStart();

        $this->assertIsInt($seconds);
        $this->assertGreaterThanOrEqual(0, $seconds);
        $this->assertSame(5, $seconds);

        Carbon::setTestNow();
    }

    public function test_seconds_since_start_e_null_senza_started_at(): void
    {
        $run = ImportRun::create(['trigger_type' => 'manual', 'status' => 'pending']);

        $this->assertNull($run->secondsSinceStart());
    }
}
