<?php

declare(strict_types=1);

namespace UnniSign\Exception;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $responseBody,
    ) {
        parent::__construct("UnniSign API request failed (HTTP {$statusCode}).");
    }
}
