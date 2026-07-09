<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.backup.rclone_remote' => 'gdrive:eclisse-sync-backups']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/backups'));

        parent::tearDown();
    }

    public function test_esegue_dump_upload_e_pulizia_quando_tutto_va_a_buon_fine(): void
    {
        Process::fake([
            '*mysqldump*' => Process::result(''),
            '*rclone*copy*' => Process::result(''),
            '*rclone*delete*' => Process::result(''),
        ]);

        $this->artisan('backup:run')->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains(self::commandString($process), 'mysqldump'));
        Process::assertRan(fn ($process) => str_contains(self::commandString($process), 'rclone') && str_contains(self::commandString($process), 'copy'));
        Process::assertRan(fn ($process) => str_contains(self::commandString($process), 'rclone') && str_contains(self::commandString($process), 'delete') && str_contains(self::commandString($process), '30d'));
    }

    public function test_non_carica_niente_se_il_dump_fallisce(): void
    {
        Process::fake([
            '*mysqldump*' => Process::result(exitCode: 1, errorOutput: 'access denied'),
        ]);

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNotRan(fn ($process) => str_contains(self::commandString($process), 'rclone'));
    }

    public function test_fallisce_se_lupload_rclone_fallisce(): void
    {
        Process::fake([
            '*mysqldump*' => Process::result(''),
            '*rclone*copy*' => Process::result(exitCode: 1, errorOutput: 'connessione rifiutata'),
        ]);

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNotRan(fn ($process) => str_contains(self::commandString($process), 'delete'));
    }

    public function test_fallisce_subito_se_il_remote_non_e_configurato(): void
    {
        config(['services.backup.rclone_remote' => null]);

        Process::fake();

        $this->artisan('backup:run')->assertExitCode(1);

        Process::assertNothingRan();
    }

    private static function commandString($process): string
    {
        return is_array($process->command) ? implode(' ', $process->command) : $process->command;
    }
}
