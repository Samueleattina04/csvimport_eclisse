<?php

namespace App\Services\Csv\Dto;

readonly class DownloadResult
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $sizeBytes,
        public int $httpStatus,
    ) {}
}
