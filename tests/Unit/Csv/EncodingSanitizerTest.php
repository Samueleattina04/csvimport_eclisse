<?php

namespace Tests\Unit\Csv;

use App\Services\Csv\EncodingSanitizer;
use PHPUnit\Framework\TestCase;

class EncodingSanitizerTest extends TestCase
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

    private function tempPath(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.uniqid($prefix, true).'.csv';
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_lascia_intatto_un_file_gia_interamente_utf8(): void
    {
        $source = $this->tempPath('utf8_');
        file_put_contents($source, "a;b\n1;2\n");

        $result = (new EncodingSanitizer)->sanitize($source, $this->tempPath('out_'));

        $this->assertSame($source, $result->path);
        $this->assertFalse($result->wasModified());
        $this->assertSame([], $result->fallbackLines);
    }

    public function test_converte_in_utf8_solo_le_righe_codificate_in_windows_1252(): void
    {
        $source = $this->tempPath('mixed_');
        $destination = $this->tempPath('mixed_out_');

        // riga 1: header pulito. riga 2: valida UTF-8. riga 3: "é" in Windows-1252 puro (0xE9).
        $badLine = "15327661;5715732485630;;13,35;53,00;\"\";0;Donna;Coll;;Cocoa Cr\xE9me;XS;0.00;Cat;;;combinations;both;Only;\n";
        file_put_contents($source, "CODICE;EAN\n1;2\n".$badLine);

        $result = (new EncodingSanitizer)->sanitize($source, $destination);

        $this->assertSame($destination, $result->path);
        $this->assertTrue($result->wasModified());
        $this->assertSame([3], $result->fallbackLines);

        $rewritten = file_get_contents($destination);
        $this->assertTrue(mb_check_encoding($rewritten, 'UTF-8'));
        $this->assertStringContainsString('Cocoa Créme', $rewritten);
    }
}
