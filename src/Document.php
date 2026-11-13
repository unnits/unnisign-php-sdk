<?php

declare(strict_types=1);

namespace UnniSign;

use InvalidArgumentException;

final readonly class Document
{
    public function __construct(
        public string $uuid,
        public string $contents,
        public string $filename,
        public ?string $pdfTitle = null,
    ) {}

    public static function fromPath(string $uuid, string $path, ?string $pdfTitle = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The document must be a readable local file.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException('Unable to read the document.');
        }

        return new self($uuid, $contents, basename($path), $pdfTitle);
    }
}
