<?php

namespace Tests\Feature\Shopify;

use App\Services\Shopify\CollectionResolver;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ShopifySyncException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectionResolverTest extends TestCase
{
    use RefreshDatabase;

    private function client(): ShopifyGraphQLClient
    {
        return new ShopifyGraphQLClient(storeDomain: 'test-store.myshopify.com', accessToken: 'shpat_test', retryDelayMs: 0);
    }

    public function test_riusa_una_collezione_esistente_con_titolo_corrispondente(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['collections' => ['nodes' => [['id' => 'gid://shopify/Collection/1', 'title' => 'Uomo']]]],
            ], 200),
        ]);

        $id = (new CollectionResolver($this->client()))->resolveId('Uomo');

        $this->assertSame('gid://shopify/Collection/1', $id);
        Http::assertSentCount(1); // solo la ricerca, nessuna creazione
    }

    public function test_crea_la_collezione_se_non_esiste_ancora(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            if (str_contains($request->body(), 'FindCollection')) {
                return Http::response(['data' => ['collections' => ['nodes' => []]]], 200);
            }

            return Http::response([
                'data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/99'], 'userErrors' => []]],
            ], 200);
        });

        $id = (new CollectionResolver($this->client()))->resolveId('Outlet Estate 26');

        $this->assertSame('gid://shopify/Collection/99', $id);
        $this->assertSame(2, $calls); // ricerca (non trovata) + creazione
    }

    public function test_non_richiama_shopify_una_seconda_volta_per_lo_stesso_nome(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([
                'data' => ['collections' => ['nodes' => [['id' => 'gid://shopify/Collection/1', 'title' => 'Uomo']]]],
            ], 200);
        });

        $resolver = new CollectionResolver($this->client());
        $resolver->resolveId('Uomo');
        $resolver->resolveId('Uomo');

        $this->assertSame(1, $calls);
    }

    public function test_lancia_eccezione_se_la_creazione_fallisce(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->body(), 'FindCollection')) {
                return Http::response(['data' => ['collections' => ['nodes' => []]]], 200);
            }

            return Http::response([
                'data' => ['collectionCreate' => ['collection' => null, 'userErrors' => [['field' => ['title'], 'message' => 'Titolo duplicato']]]],
            ], 200);
        });

        $this->expectException(ShopifySyncException::class);
        (new CollectionResolver($this->client()))->resolveId('Duplicata');
    }
}
