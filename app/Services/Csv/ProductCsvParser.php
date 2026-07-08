<?php

namespace App\Services\Csv;

use App\Services\Csv\Dto\ParsedProduct;
use App\Services\Csv\Dto\ParsedVariant;
use App\Services\Csv\Dto\ParseLogEntry;
use App\Services\Csv\Dto\ParseSummary;
use App\Services\Csv\Support\NumberParser;
use League\Csv\Reader;
use League\Csv\SyntaxError;

/**
 * Trasforma il CSV del gestionale (una riga per variante, titolo valorizzato solo
 * sulla prima riga di ogni gruppo) in oggetti ParsedProduct pronti per lo staging.
 *
 * Applica le regole concordate sui dati reali:
 * - quantita' negativa -> clampata a 0 (il valore originale resta in quantityRaw), warning in log
 * - CATEGORIA/COLLEZIONE/vendor/gender/meta del prodotto presi dalla riga con il titolo
 * - varianti duplicate (stesso colore+taglia nello stesso prodotto) -> quantita' sommate,
 *   si tiene il primo EAN, warning in log
 * - COLORE/TAGLIA mancanti -> placeholder "Standard"/"Unica" (Shopify richiede un valore)
 * - nessuna riga del gruppo ha il titolo -> fallback al CODICE ARTICOLO, warning in log
 */
class ProductCsvParser
{
    private const EXPECTED_HEADERS = [
        'CODICE ARTICOLO', 'CODICE EAN', 'NOME PRODOTTO', 'COSTO ACQUISTO', 'COSTO LISTINO',
        'DESCRIZIONE', 'QUANTITA', 'GENERE', 'COLLEZIONE', 'CATEGORIA - PRINCIPALE',
        'ATTRIBUTE GROUP: COLORE', 'ATTRIBUTE GROUP: TAGLIA', 'ATTRIBUTE GROUP: LUNGHEZZA',
        'CATEGORIA', 'URL IMMAGINE', 'URL IMMAGINI COMBINAZIONE', 'TIPOLOGIA PRODOTTO',
        'VISIBILITA', 'BRAND', 'META DESCRIPTION',
    ];

    /**
     * @param  callable(ParsedProduct): void  $onProduct  invocata per ogni prodotto completo, cosi' il chiamante puo' persisterlo subito senza accumulare tutto in memoria
     */
    public function parse(string $sanitizedCsvPath, callable $onProduct): ParseSummary
    {
        $handleResolver = $this->buildHandleResolver($sanitizedCsvPath);

        $reader = $this->openReader($sanitizedCsvPath);

        $logs = [];
        $csvRowCount = 0;
        $productsParsed = 0;
        $productsFailed = 0;
        $variantsParsed = 0;

        $closedCodes = [];
        $currentCodice = null;
        $currentRows = [];

        $flush = function () use (&$currentCodice, &$currentRows, &$logs, &$productsParsed, &$productsFailed, &$variantsParsed, $handleResolver, $onProduct) {
            if ($currentCodice === null || $currentRows === []) {
                return;
            }

            [$product, $groupLogs] = $this->buildProduct($currentCodice, $currentRows, $handleResolver);
            array_push($logs, ...$groupLogs);

            if ($product === null) {
                $productsFailed++;

                return;
            }

            $productsParsed++;
            $variantsParsed += count($product->variants);
            $onProduct($product);
        };

        foreach ($reader->getRecords() as $offset => $record) {
            // +2: offset 0-based dopo l'header, la riga fisica nel file (1-based) e' offset+2.
            $csvRowNumber = $offset + 2;
            $csvRowCount++;

            $codice = trim($record['CODICE ARTICOLO'] ?? '');
            if ($codice === '') {
                $logs[] = ParseLogEntry::error('Riga senza CODICE ARTICOLO, scartata.', null, null, $csvRowNumber);

                continue;
            }

            if ($codice !== $currentCodice) {
                if (isset($closedCodes[$codice])) {
                    $logs[] = ParseLogEntry::error(
                        'CODICE ARTICOLO ricomparso in un blocco non contiguo del CSV: riga scartata per evitare di spezzare il prodotto in due.',
                        $codice,
                        null,
                        $csvRowNumber,
                    );

                    continue;
                }

                $flush();

                if ($currentCodice !== null) {
                    $closedCodes[$currentCodice] = true;
                }

                $currentCodice = $codice;
                $currentRows = [];
            }

            $currentRows[] = ['row' => $record, 'line' => $csvRowNumber];
        }
        $flush();

        return new ParseSummary($csvRowCount, $productsParsed, $productsFailed, $variantsParsed, $logs);
    }

    private function openReader(string $path): Reader
    {
        $reader = Reader::createFromPath($path, 'r');
        $reader->setDelimiter(';');
        $reader->setHeaderOffset(0);

        try {
            $header = $reader->getHeader();
        } catch (SyntaxError $e) {
            throw new \RuntimeException('CSV illeggibile o vuoto: '.$e->getMessage(), previous: $e);
        }

        $missing = array_diff(self::EXPECTED_HEADERS, $header);
        if ($missing !== []) {
            throw new \RuntimeException('Struttura del CSV cambiata, colonne mancanti: '.implode(', ', $missing));
        }

        return $reader;
    }

    /**
     * Prima passata leggera: serve solo a raccogliere tutti i CODICE ARTICOLO per
     * poter risolvere gli handle in modo deterministico (vedi HandleResolver).
     */
    private function buildHandleResolver(string $path): HandleResolver
    {
        $resolver = new HandleResolver;
        $reader = $this->openReader($path);

        foreach ($reader->getRecords() as $record) {
            $codice = trim($record['CODICE ARTICOLO'] ?? '');
            if ($codice !== '') {
                $resolver->add($codice);
            }
        }

        $resolver->build();

        return $resolver;
    }

    /**
     * @param  list<array{row: array<string, string>, line: int}>  $rows
     * @return array{0: ?ParsedProduct, 1: list<ParseLogEntry>}
     */
    private function buildProduct(string $codiceArticolo, array $rows, HandleResolver $handleResolver): array
    {
        $logs = [];

        $titleIndex = null;
        foreach ($rows as $i => $entry) {
            if (trim($entry['row']['NOME PRODOTTO'] ?? '') !== '') {
                $titleIndex = $i;
                break;
            }
        }

        if ($titleIndex === null) {
            $logs[] = ParseLogEntry::warning(
                'Nessuna riga del gruppo ha NOME PRODOTTO valorizzato: uso il CODICE ARTICOLO come titolo provvisorio.',
                $codiceArticolo,
                csvRowNumber: $rows[0]['line'],
            );
            $titleIndex = 0;
        }

        $titleRow = $rows[$titleIndex]['row'];

        $title = trim($titleRow['NOME PRODOTTO']) !== '' ? trim($titleRow['NOME PRODOTTO']) : $codiceArticolo;
        $mainImageUrl = $this->firstNonEmptyImage($rows, $titleIndex);
        $visibilityRaw = trim($titleRow['VISIBILITA'] ?? '');
        $status = $this->resolveStatus($visibilityRaw, $codiceArticolo, $rows[$titleIndex]['line'], $logs);

        [$variants, $variantLogs] = $this->buildVariants($codiceArticolo, $rows);
        array_push($logs, ...$variantLogs);

        if ($variants === []) {
            $logs[] = ParseLogEntry::error('Nessuna variante valida per questo prodotto: scartato.', $codiceArticolo, csvRowNumber: $rows[0]['line']);

            return [null, $logs];
        }

        $bodyHtml = $titleRow['DESCRIZIONE'] !== '' ? $titleRow['DESCRIZIONE'] : null;
        $vendor = trim($titleRow['BRAND'] ?? '') !== '' ? trim($titleRow['BRAND']) : null;
        $gender = trim($titleRow['GENERE'] ?? '') !== '' ? trim($titleRow['GENERE']) : null;
        $categoryPath = trim($titleRow['CATEGORIA'] ?? '') !== '' ? trim($titleRow['CATEGORIA']) : null;
        $collectionName = trim($titleRow['COLLEZIONE'] ?? '') !== '' ? trim($titleRow['COLLEZIONE']) : null;
        $metaDescription = trim($titleRow['META DESCRIPTION'] ?? '') !== '' ? trim($titleRow['META DESCRIPTION']) : null;

        $handle = $handleResolver->resolve($codiceArticolo);

        $contentHash = hash('sha256', json_encode([
            $handle, $title, $bodyHtml, $vendor, $gender, $categoryPath, $collectionName, $metaDescription, $mainImageUrl, $status,
        ]));

        $product = new ParsedProduct(
            codiceArticolo: $codiceArticolo,
            handle: $handle,
            title: $title,
            bodyHtml: $bodyHtml,
            vendor: $vendor,
            gender: $gender,
            categoryPath: $categoryPath,
            collectionName: $collectionName,
            metaDescription: $metaDescription,
            mainImageUrl: $mainImageUrl,
            visibilityRaw: $visibilityRaw !== '' ? $visibilityRaw : null,
            status: $status,
            contentHash: $contentHash,
            variants: $variants,
        );

        return [$product, $logs];
    }

    private function resolveStatus(string $visibilityRaw, string $codiceArticolo, int $csvRowNumber, array &$logs): string
    {
        $normalized = strtolower($visibilityRaw);

        if ($normalized === 'both') {
            return 'published';
        }

        if ($normalized === '') {
            return 'draft';
        }

        $logs[] = ParseLogEntry::warning(
            "Valore VISIBILITA non riconosciuto [{$visibilityRaw}]: prodotto impostato come draft.",
            $codiceArticolo,
            csvRowNumber: $csvRowNumber,
        );

        return 'draft';
    }

    /**
     * URL IMMAGINE, cosi' come URL IMMAGINI COMBINAZIONE, puo' contenere piu' URL
     * separati da ";" (spesso lo stesso ripetuto): si usa sempre il primo.
     *
     * @param  list<array{row: array<string, string>, line: int}>  $rows
     */
    private function firstNonEmptyImage(array $rows, int $titleIndex): ?string
    {
        $candidates = [$rows[$titleIndex]['row']];
        foreach ($rows as $entry) {
            $candidates[] = $entry['row'];
        }

        foreach ($candidates as $row) {
            $main = $this->splitImageList($row['URL IMMAGINE'] ?? '');
            if ($main !== []) {
                return $main[0];
            }
        }

        foreach ($candidates as $row) {
            $combo = $this->splitImageList($row['URL IMMAGINI COMBINAZIONE'] ?? '');
            if ($combo !== []) {
                return $combo[0];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function splitImageList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(';', $raw)), fn (string $url) => $url !== ''));
    }

    /**
     * @param  list<array{row: array<string, string>, line: int}>  $rows
     * @return array{0: list<ParsedVariant>, 1: list<ParseLogEntry>}
     */
    private function buildVariants(string $codiceArticolo, array $rows): array
    {
        $logs = [];
        $candidates = [];

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $line = $entry['line'];

            $ean = trim($row['CODICE EAN'] ?? '');
            if ($ean === '') {
                $logs[] = ParseLogEntry::error('EAN mancante, variante saltata.', $codiceArticolo, null, $line);

                continue;
            }

            $price = NumberParser::parseDecimal($row['COSTO LISTINO'] ?? '');
            if ($price === null) {
                $logs[] = ParseLogEntry::error('Prezzo (COSTO LISTINO) mancante o non numerico, variante saltata.', $codiceArticolo, $ean, $line);

                continue;
            }

            $color = trim($row['ATTRIBUTE GROUP: COLORE'] ?? '');
            if ($color === '') {
                $logs[] = ParseLogEntry::warning('COLORE mancante, uso il placeholder "Standard".', $codiceArticolo, $ean, $line);
                $color = 'Standard';
            }

            $size = trim($row['ATTRIBUTE GROUP: TAGLIA'] ?? '');
            if ($size === '') {
                $logs[] = ParseLogEntry::warning('TAGLIA mancante, uso il placeholder "Unica".', $codiceArticolo, $ean, $line);
                $size = 'Unica';
            }

            $length = trim($row['ATTRIBUTE GROUP: LUNGHEZZA'] ?? '');
            $length = in_array($length, ['', '0.00', '0,00'], true) ? null : $length;

            $cost = NumberParser::parseDecimal($row['COSTO ACQUISTO'] ?? '');
            $quantityRaw = NumberParser::parseQuantity($row['QUANTITA'] ?? '');
            $images = $this->splitImageList($row['URL IMMAGINI COMBINAZIONE'] ?? '');

            $candidates[] = [
                'ean' => $ean,
                'color' => $color,
                'size' => $size,
                'length' => $length,
                'price' => $price,
                'cost' => $cost,
                'quantityRaw' => $quantityRaw,
                'imageUrl' => $images[0] ?? null,
                'line' => $line,
            ];
        }

        // Consolida le combinazioni colore+taglia duplicate (stesso prodotto, EAN diverso):
        // Shopify non permette due varianti con le stesse opzioni sullo stesso prodotto.
        $grouped = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['color'].'|||'.$candidate['size'];
            $grouped[$key][] = $candidate;
        }

        $variants = [];
        $position = 0;
        foreach ($grouped as $group) {
            $primary = $group[0];
            $quantityRawSum = array_sum(array_column($group, 'quantityRaw'));

            if (count($group) > 1) {
                $logs[] = ParseLogEntry::warning(
                    sprintf(
                        'Combinazione colore+taglia duplicata (%s/%s): quantita\' sommate, mantenuto EAN %s.',
                        $primary['color'],
                        $primary['size'],
                        $primary['ean'],
                    ),
                    $codiceArticolo,
                    $primary['ean'],
                    $primary['line'],
                    ['eans_uniti' => array_column($group, 'ean')],
                );
            }

            $quantity = max(0, $quantityRawSum);
            if ($quantityRawSum < 0) {
                $logs[] = ParseLogEntry::warning(
                    "Quantita' negativa nel CSV ({$quantityRawSum}), impostata a 0.",
                    $codiceArticolo,
                    $primary['ean'],
                    $primary['line'],
                );
            }

            $contentHash = hash('sha256', json_encode([
                $primary['ean'], $primary['color'], $primary['size'], $primary['length'],
                $primary['price'], $primary['cost'], $quantity, $primary['imageUrl'],
            ]));

            $variants[] = new ParsedVariant(
                codiceEan: $primary['ean'],
                color: $primary['color'],
                size: $primary['size'],
                length: $primary['length'],
                price: $primary['price'],
                cost: $primary['cost'],
                quantity: $quantity,
                quantityRaw: $quantityRawSum,
                imageUrl: $primary['imageUrl'],
                position: $position++,
                contentHash: $contentHash,
            );
        }

        return [$variants, $logs];
    }
}
