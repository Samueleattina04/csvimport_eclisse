<?php

namespace App\Services\Shopify\Support;

/**
 * Le mutation Shopify vogliono/restituiscono ID globali tipo
 * "gid://shopify/Product/123456789". Noi conserviamo solo la parte numerica
 * nel DB (colonne unsignedBigInteger, piu' semplici da indicizzare/joinare):
 * il GID e' sempre deterministico a partire da tipo+numero, quindi si puo'
 * ricostruire quando serve richiamare l'API.
 */
class ShopifyGid
{
    public static function toGid(string $type, int $id): string
    {
        return "gid://shopify/{$type}/{$id}";
    }

    public static function toNumericId(string $gid): int
    {
        $parts = explode('/', $gid);

        return (int) end($parts);
    }
}
