<?php

namespace App\Services\Csv\Dto;

readonly class ParsedProduct
{
    /**
     * @param  list<ParsedVariant>  $variants
     */
    public function __construct(
        public string $codiceArticolo,
        public string $handle,
        public string $title,
        public ?string $bodyHtml,
        public ?string $vendor,
        public ?string $gender,
        public ?string $categoryPath,
        public ?string $collectionName,
        public ?string $metaDescription,
        public ?string $mainImageUrl,
        public ?string $visibilityRaw,
        public string $status,
        public string $contentHash,
        public array $variants,
    ) {}
}
