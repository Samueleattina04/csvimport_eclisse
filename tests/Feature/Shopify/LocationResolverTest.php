<?php

namespace Tests\Feature\Shopify;

use App\Services\Shopify\LocationResolver;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\ShopifySyncException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocationResolverTest extends TestCase
{
    use RefreshDatabase;

    private function client(): ShopifyGraphQLClient
    {
        return new ShopifyGraphQLClient(storeDomain: 'test-store.myshopify.com', accessToken: 'shpat_test', retryDelayMs: 0);
    }

    public function test_restituisce_lid_della_prima_location_attiva(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'Magazzino centrale']]]],
            ], 200),
        ]);

        $id = (new LocationResolver($this->client()))->primaryLocationId();

        $this->assertSame('gid://shopify/Location/1', $id);
    }

    public function test_mette_in_cache_il_risultato_evitando_chiamate_ripetute(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([
                'data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'Magazzino']]]],
            ], 200);
        });

        $resolver = new LocationResolver($this->client());
        $resolver->primaryLocationId();
        $resolver->primaryLocationId();
        $resolver->primaryLocationId();

        $this->assertSame(1, $calls);
    }

    public function test_lancia_eccezione_se_non_ci_sono_location_attive(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['locations' => ['nodes' => []]]], 200),
        ]);

        $this->expectException(ShopifySyncException::class);
        (new LocationResolver($this->client()))->primaryLocationId();
    }
}
