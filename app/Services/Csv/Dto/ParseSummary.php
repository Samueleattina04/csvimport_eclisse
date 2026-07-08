<?php

namespace App\Services\Csv\Dto;

readonly class ParseSummary
{
    /**
     * @param  list<ParseLogEntry>  $logs
     */
    public function __construct(
        public int $csvRowCount,
        public int $productsParsed,
        public int $productsFailed,
        public int $variantsParsed,
        public array $logs,
    ) {}
}
