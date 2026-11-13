<?php

declare(strict_types=1);

namespace UnniSign\Exception;

use RuntimeException;

final class UnexpectedResponseException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('UnniSign returned an invalid signing procedure response.');
    }
}
