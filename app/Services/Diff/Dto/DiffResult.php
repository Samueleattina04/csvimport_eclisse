<?php

namespace App\Services\Diff\Dto;

readonly class DiffResult
{
    /**
     * @param  list<ProductDiff>  $products
     */
    public function __construct(
        public array $products,
        public int $productsCreated,
        public int $productsUpdated,
        public int $productsUnchanged,
        public int $productsRemoved,
        public int $variantsCreated,
        public int $variantsUpdated,
        public int $variantsRemoved,
    ) {}
}
