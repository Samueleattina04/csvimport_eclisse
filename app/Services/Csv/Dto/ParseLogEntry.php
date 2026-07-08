<?php

namespace App\Services\Csv\Dto;

readonly class ParseLogEntry
{
    public function __construct(
        public string $level,
        public string $message,
        public ?string $codiceArticolo = null,
        public ?string $codiceEan = null,
        public ?int $csvRowNumber = null,
        public ?array $context = null,
    ) {}

    public static function warning(string $message, ?string $codiceArticolo = null, ?string $codiceEan = null, ?int $csvRowNumber = null, ?array $context = null): self
    {
        return new self('warning', $message, $codiceArticolo, $codiceEan, $csvRowNumber, $context);
    }

    public static function error(string $message, ?string $codiceArticolo = null, ?string $codiceEan = null, ?int $csvRowNumber = null, ?array $context = null): self
    {
        return new self('error', $message, $codiceArticolo, $codiceEan, $csvRowNumber, $context);
    }

    public static function info(string $message, ?string $codiceArticolo = null, ?array $context = null): self
    {
        return new self('info', $message, $codiceArticolo, null, null, $context);
    }
}
