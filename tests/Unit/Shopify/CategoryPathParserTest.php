<?php

namespace Tests\Unit\Shopify;

use App\Services\Shopify\Support\CategoryPathParser;
use PHPUnit\Framework\TestCase;

class CategoryPathParserTest extends TestCase
{
    public function test_estrae_i_due_nomi_da_un_percorso_doppio_reale(): void
    {
        $names = CategoryPathParser::collectionNames('Root|Prodotti|Uomo||Root|Prodotti|Uomo|Nuova Collezione Estate 25');

        $this->assertSame(['Uomo', 'Nuova Collezione Estate 25'], $names);
    }

    public function test_estrae_i_nomi_da_un_altro_percorso_reale(): void
    {
        $names = CategoryPathParser::collectionNames('Root|Prodotti|Donna||Root|Prodotti|Donna|Outlet Inverno 25/26');

        $this->assertSame(['Donna', 'Outlet Inverno 25/26'], $names);
    }

    public function test_restituisce_array_vuoto_per_valori_vuoti_o_nulli(): void
    {
        $this->assertSame([], CategoryPathParser::collectionNames(null));
        $this->assertSame([], CategoryPathParser::collectionNames(''));
        $this->assertSame([], CategoryPathParser::collectionNames('   '));
    }

    public function test_gestisce_un_percorso_singolo_senza_doppio_pipe(): void
    {
        $this->assertSame(['Uomo'], CategoryPathParser::collectionNames('Root|Prodotti|Uomo'));
    }
}
