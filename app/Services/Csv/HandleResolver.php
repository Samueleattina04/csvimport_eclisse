<?php

namespace App\Services\Csv;

use Illuminate\Support\Str;

/**
 * Genera l'Handle Shopify a partire dal CODICE ARTICOLO. Su dati reali esistono
 * CODICE ARTICOLO diversi che producono lo stesso slug (es. "56605 0000" e
 * "56605-0000" diventano entrambi "56605-0000"): per quei casi si aggiunge un
 * suffisso deterministico calcolato dal codice stesso, cosi' l'handle resta
 * stabile per sempre e non dipende dall'ordine delle righe nel CSV.
 *
 * Va "riempito" con tutti i CODICE ARTICOLO del file (build()) prima di poter
 * risolvere gli handle (resolve()), perche' una collisione si puo' riconoscere
 * solo confrontando un codice con tutti gli altri.
 */
class HandleResolver
{
    /** @var array<string, list<string>> slug di base => lista di codici articolo che ci finiscono */
    private array $slugToCodes = [];

    /** @var array<string, string> codice articolo => handle finale */
    private array $resolved = [];

    public function add(string $codiceArticolo): void
    {
        if (isset($this->resolved[$codiceArticolo])) {
            return;
        }

        $baseSlug = $this->slugify($codiceArticolo);
        $this->slugToCodes[$baseSlug][] = $codiceArticolo;
    }

    /**
     * Da chiamare una volta sola dopo aver aggiunto tutti i codici articolo del run.
     */
    public function build(): void
    {
        foreach ($this->slugToCodes as $baseSlug => $codes) {
            $codes = array_values(array_unique($codes));

            if (count($codes) === 1) {
                $this->resolved[$codes[0]] = $baseSlug;

                continue;
            }

            foreach ($codes as $codiceArticolo) {
                $suffix = substr(sha1($codiceArticolo), 0, 6);
                $this->resolved[$codiceArticolo] = "{$baseSlug}-{$suffix}";
            }
        }
    }

    public function resolve(string $codiceArticolo): string
    {
        return $this->resolved[$codiceArticolo]
            ?? throw new \RuntimeException("Handle non risolto per il codice articolo [{$codiceArticolo}]: chiamare build() dopo add().");
    }

    /**
     * @return array<string, list<string>> solo gli slug di base con piu' di un codice articolo
     */
    public function collisions(): array
    {
        return array_filter($this->slugToCodes, fn (array $codes) => count(array_unique($codes)) > 1);
    }

    private function slugify(string $codiceArticolo): string
    {
        $slug = Str::slug($codiceArticolo);

        return $slug !== '' ? $slug : 'prodotto-'.substr(sha1($codiceArticolo), 0, 8);
    }
}
