<?php

namespace App\Services\Diff\Dto;

readonly class ProductDiff
{
    /**
     * @param  'create'|'update'|'remove'|'unchanged'  $action
     * @param  array<string, array{0: mixed, 1: mixed}>  $fieldChanges  campo => [vecchio, nuovo], solo per action=update
     * @param  array<string, mixed>|null  $staging  dati grezzi del prodotto in staging (null se action=remove)
     * @param  array<string, mixed>|null  $existing  dati grezzi del prodotto canonico attuale (null se action=create)
     * @param  list<VariantDiff>  $variants
     */
    public function __construct(
        public string $action,
        public string $codiceArticolo,
        public array $fieldChanges,
        public ?array $staging,
        public ?array $existing,
        public array $variants,
    ) {}

    public function title(): string
    {
        return $this->staging['title'] ?? $this->existing['title'] ?? $this->codiceArticolo;
    }
}
