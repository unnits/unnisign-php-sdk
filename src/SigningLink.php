<?php

declare(strict_types=1);

namespace UnniSign;

use stdClass;
use UnniSign\Exception\UnexpectedResponseException;

final readonly class SigningLink
{
    public function __construct(
        public string $value,
        public string $expiresAt,
        public ?string $signer = null,
    ) {}

    /** @internal */
    public static function fromResponse(mixed $data): self
    {
        if (!$data instanceof stdClass || !is_string($data->value ?? null) || !is_string($data->expires_at ?? null)) {
            throw new UnexpectedResponseException();
        }

        $signer = null;

        if (property_exists($data, 'signer')) {
            if (!is_string($data->signer)) {
                throw new UnexpectedResponseException();
            }

            $signer = $data->signer;
        }

        return new self($data->value, $data->expires_at, $signer);
    }
}
