<?php

namespace App\Services\Shopify\Dto;

readonly class ShopifyMutationResult
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<array<string, mixed>>  $errors  errori GraphQL a livello di richiesta (query malformata, auth, ecc.)
     * @param  list<array<string, mixed>>  $userErrors  errori applicativi restituiti dalla mutation stessa (es. campo non valido)
     */
    public function __construct(
        public bool $success,
        public ?array $data,
        public array $errors,
        public array $userErrors,
        public int $httpStatus,
        public bool $wasThrottled = false,
    ) {}

    public function firstErrorMessage(): ?string
    {
        if ($this->errors !== []) {
            return $this->errors[0]['message'] ?? 'Errore GraphQL sconosciuto.';
        }

        if ($this->userErrors !== []) {
            return $this->userErrors[0]['message'] ?? 'Errore applicativo Shopify sconosciuto.';
        }

        return null;
    }
}
