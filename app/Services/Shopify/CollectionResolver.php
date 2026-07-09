<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Cache;

/**
 * Trova una collezione Shopify per titolo esatto, o la crea se non esiste
 * ("assegna alle categorie gia' esistenti uguali a quelle del CSV, se la
 * categoria non esiste la crea" - deciso col cliente). Il risultato resta in
 * cache: una volta risolto/creato un nome non serve interrogare Shopify ad
 * ogni prodotto che lo referenzia.
 */
class CollectionResolver
{
    public function __construct(private readonly ShopifyGraphQLClient $client) {}

    public function resolveId(string $name, ?int $importRunId = null): string
    {
        return Cache::remember('shopify:collection_id:'.md5($name), now()->addDay(), function () use ($name, $importRunId) {
            return $this->findByTitle($name, $importRunId) ?? $this->create($name, $importRunId);
        });
    }

    private function findByTitle(string $name, ?int $importRunId): ?string
    {
        $query = <<<'GQL'
            query FindCollection($query: String!) {
                collections(first: 5, query: $query) {
                    nodes { id title }
                }
            }
            GQL;

        $result = $this->client->call($query, ['query' => 'title:'.json_encode($name)], 'findCollectionByTitle', $importRunId, $name);

        if (! $result->success) {
            return null;
        }

        foreach ($result->data['collections']['nodes'] ?? [] as $node) {
            if (($node['title'] ?? null) === $name) {
                return $node['id'];
            }
        }

        return null;
    }

    private function create(string $name, ?int $importRunId): string
    {
        $mutation = <<<'GQL'
            mutation CreateCollection($input: CollectionInput!) {
                collectionCreate(input: $input) {
                    collection { id }
                    userErrors { field message }
                }
            }
            GQL;

        $result = $this->client->call($mutation, ['input' => ['title' => $name]], 'collectionCreate', $importRunId, $name);

        if (! $result->success) {
            throw new ShopifySyncException("Impossibile creare la collezione Shopify \"{$name}\": ".($result->firstErrorMessage() ?? 'errore sconosciuto.'));
        }

        return $result->data['collectionCreate']['collection']['id'];
    }
}
