<?php

namespace App\Services\Csv;

readonly class SanitizeResult
{
    /**
     * @param  array<int, int>  $fallbackLines  numeri di riga (1-based, header incluso) che hanno richiesto la conversione da Windows-1252
     */
    public function __construct(
        public string $path,
        public array $fallbackLines,
    ) {}

    public function wasModified(): bool
    {
        return $this->fallbackLines !== [];
    }
}
