<?php

declare(strict_types=1);

namespace UnniSign;

use JsonException;
use stdClass;
use UnniSign\Exception\UnexpectedResponseException;

final readonly class SigningProcedure
{
    /** @param list<SigningLink> $signingLinks */
    public function __construct(
        public string $uuid,
        public ?string $redirectUrl,
        public array $signingLinks,
    ) {}

    /** @internal */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedResponseException();
        }

        if (
            !$data instanceof stdClass
            || !is_string($data->uuid ?? null)
            || !property_exists($data, 'redirect_url')
            || $data->redirect_url !== null && !is_string($data->redirect_url)
            || !is_array($data->signing_links ?? null)
        ) {
            throw new UnexpectedResponseException();
        }

        $links = [];

        foreach ($data->signing_links as $link) {
            $links[] = SigningLink::fromResponse($link);
        }

        return new self($data->uuid, $data->redirect_url, $links);
    }
}
