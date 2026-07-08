<?php

namespace Tests\Feature\Csv;

use App\Services\Csv\CsvDownloader;
use App\Services\Csv\CsvDownloadException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsvDownloaderTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('download_', true).'.csv';
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_scarica_il_file_e_calcola_lo_sha256(): void
    {
        Http::fake([
            'gestionale.test/*' => Http::response("a;b\n1;2\n", 200),
        ]);

        $destination = $this->tempPath();
        $result = (new CsvDownloader)->download('https://gestionale.test/export.csv', $destination);

        $this->assertSame($destination, $result->path);
        $this->assertSame(200, $result->httpStatus);
        $this->assertGreaterThan(0, $result->sizeBytes);
        $this->assertSame(hash('sha256', "a;b\n1;2\n"), $result->sha256);
    }

    public function test_lancia_eccezione_se_la_risposta_http_non_e_di_successo(): void
    {
        Http::fake([
            'gestionale.test/*' => Http::response('Not Found', 404),
        ]);

        $this->expectException(CsvDownloadException::class);
        (new CsvDownloader(retries: 1, retryDelayMs: 0))->download('https://gestionale.test/export.csv', $this->tempPath());
    }

    public function test_lancia_eccezione_se_il_file_scaricato_e_vuoto(): void
    {
        Http::fake([
            'gestionale.test/*' => Http::response('', 200),
        ]);

        $this->expectException(CsvDownloadException::class);
        (new CsvDownloader(retries: 1, retryDelayMs: 0))->download('https://gestionale.test/export.csv', $this->tempPath());
    }
}
