<?php

namespace App\Services\Diff\Dto;

readonly class VariantDiff
{
    /**
     * @param  'create'|'update'|'remove'|'unchanged'  $action
     * @param  array<string, array{0: mixed, 1: mixed}>  $fieldChanges  campo => [vecchio, nuovo], solo per action=update
     * @param  array<string, mixed>|null  $staging  dati grezzi della variante in staging (null se action=remove)
     * @param  array<string, mixed>|null  $existing  dati grezzi della variante canonica attuale (null se action=create)
     */
    public function __construct(
        public string $action,
        public string $codiceEan,
        public array $fieldChanges,
        public ?array $staging,
        public ?array $existing,
    ) {}
}
