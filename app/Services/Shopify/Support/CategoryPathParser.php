<?php

namespace App\Services\Shopify\Support;

/**
 * Il campo CATEGORIA del CSV non e' un singolo percorso ma DUE percorsi
 * concatenati con "||", uno generico (genere) e uno specifico (collezione
 * stagionale), es.:
 *   "Root|Prodotti|Uomo||Root|Prodotti|Uomo|Nuova Collezione Estate 25"
 * Verificato su tutti i campioni reali analizzati: il pattern e' costante.
 *
 * Si interpretano tutti i segmenti non vuoti (esclusi i marcatori generici
 * "Root"/"Prodotti") come nomi di collezioni Shopify a cui il prodotto deve
 * appartenere: per l'esempio sopra -> ["Uomo", "Nuova Collezione Estate 25"].
 */
class CategoryPathParser
{
    private const IGNORED_SEGMENTS = ['root', 'prodotti'];

    /**
     * @return list<string>
     */
    public static function collectionNames(?string $categoryPath): array
    {
        if ($categoryPath === null || trim($categoryPath) === '') {
            return [];
        }

        $names = [];
        foreach (explode('|', $categoryPath) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || in_array(strtolower($segment), self::IGNORED_SEGMENTS, true)) {
                continue;
            }
            $names[$segment] = true;
        }

        return array_keys($names);
    }
}
