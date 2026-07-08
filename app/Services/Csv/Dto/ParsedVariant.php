<?php

namespace App\Services\Csv\Dto;

readonly class ParsedVariant
{
    public function __construct(
        public string $codiceEan,
        public ?string $color,
        public ?string $size,
        public ?string $length,
        public string $price,
        public ?string $cost,
        public int $quantity,
        public int $quantityRaw,
        public ?string $imageUrl,
        public int $position,
        public string $contentHash,
    ) {}
}
