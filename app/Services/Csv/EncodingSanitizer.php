<?php

namespace App\Services\Csv;

/**
 * Il CSV del gestionale e' quasi sempre UTF-8, ma alcune righe (osservato su dati
 * reali, es. "Cocoa Cr\xE9me") sono Windows-1252 puro: un parser che forza UTF-8
 * stretto va in crash su quelle righe. Qui si legge il file riga per riga (streaming,
 * memoria costante) e si converte solo cio' che non e' gia' UTF-8 valido.
 */
class EncodingSanitizer
{
    /**
     * @return SanitizeResult path del file garantito UTF-8 (l'originale se gia' pulito,
     *                        altrimenti una copia temporanea) + numero di riga delle righe corrette.
     */
    public function sanitize(string $sourcePath, string $destinationPath): SanitizeResult
    {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new \RuntimeException("Impossibile aprire il file CSV: {$sourcePath}");
        }

        $fallbackLines = [];
        $lineNumber = 0;
        $needsSanitizing = false;

        // Prima passata: verifica se serve davvero riscrivere il file.
        while (($line = fgets($source)) !== false) {
            $lineNumber++;
            if (! mb_check_encoding($line, 'UTF-8')) {
                $needsSanitizing = true;
                break;
            }
        }
        fclose($source);

        if (! $needsSanitizing) {
            return new SanitizeResult($sourcePath, []);
        }

        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'wb');
        if ($source === false || $destination === false) {
            throw new \RuntimeException('Impossibile preparare il file CSV sanitizzato.');
        }

        $lineNumber = 0;
        while (($line = fgets($source)) !== false) {
            $lineNumber++;
            if (! mb_check_encoding($line, 'UTF-8')) {
                $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
                $fallbackLines[] = $lineNumber;
            }
            fwrite($destination, $line);
        }

        fclose($source);
        fclose($destination);

        return new SanitizeResult($destinationPath, $fallbackLines);
    }
}
