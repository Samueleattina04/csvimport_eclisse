<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;

/**
 * QUANTITA nel CSV e' gia' la somma dei 5 punti vendita fisici (deciso col
 * cliente: "non importa in quale store si trovano, la somma va bene cosi'"),
 * quindi l'inventario Shopify va scritto su UNA sola location — quella
 * primaria/attiva dello store — invece di essere distribuito su piu' location.
 */
class LocationResolver
{
    public function __construct(private readonly ShopifyGraphQLClient $client) {}

    public function primaryLocationId(?int $importRunId = null): string
    {
        return Cache::remember('shopify:primary_location_id', now()->addDay(), function () use ($importRunId) {
            $query = <<<'GQL'
                query PrimaryLocation {
                    locations(first: 1, query: "status:active") {
                        nodes { id name }
                    }
                }
                GQL;

            $result = $this->client->call($query, [], 'primaryLocation', $importRunId);
            $nodes = $result->data['locations']['nodes'] ?? [];

            if (! $result->success || $nodes === []) {
                throw new ShopifySyncException(
                    'Impossibile determinare la location Shopify per l\'inventario: '
                    .($result->firstErrorMessage() ?? 'nessuna location attiva trovata.')
                );
            }

            return $nodes[0]['id'];
        });
    }
}
