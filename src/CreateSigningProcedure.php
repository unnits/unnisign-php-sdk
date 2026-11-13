<?php

declare(strict_types=1);

namespace UnniSign;

use InvalidArgumentException;

final readonly class CreateSigningProcedure
{
    /**
     * @param list<Document> $documents
     * @param array<array-key, mixed>|null $clientMetadata
     * @param array<array-key, mixed>|null $clientHeaders
     * @param list<array{name: string, value: string}>|null $settings
     */
    public function __construct(
        public array $documents,
        public bool $singleDeviceSign,
        public string $webhookUrl,
        public ?bool $headless = null,
        public ?string $redirectUrl = null,
        public ?array $clientMetadata = null,
        public ?array $clientHeaders = null,
        public ?array $settings = null,
        public ?int $presetId = null,
    ) {
        if ($documents === []) {
            throw new InvalidArgumentException('At least one document is required.');
        }
    }

    /**
     * @internal
     * @return list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}>
     */
    public function toMultipart(): array
    {
        $fields = [
            'single_device_sign' => $this->singleDeviceSign ? '1' : '0',
            'webhook_url' => $this->webhookUrl,
        ];

        if ($this->headless !== null) {
            $fields['headless'] = $this->headless ? '1' : '0';
        }

        if ($this->redirectUrl !== null) {
            $fields['redirect_url'] = $this->redirectUrl;
        }

        foreach ([
            'client_metadata' => $this->clientMetadata,
            'client_headers' => $this->clientHeaders,
            'settings' => $this->settings,
        ] as $name => $value) {
            if ($value === null) {
                continue;
            }

            $fields[$name] = json_encode($value, JSON_THROW_ON_ERROR);
        }

        if ($this->presetId !== null) {
            $fields['preset_id'] = (string) $this->presetId;
        }

        $parts = [];

        foreach ($fields as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => $value];
        }

        foreach ($this->documents as $index => $document) {
            $parts[] = ['name' => "documents[{$index}][uuid]", 'contents' => $document->uuid];
            $parts[] = [
                'name' => "documents[{$index}][file]",
                'contents' => $document->contents,
                'filename' => $document->filename,
                'headers' => ['Content-Type' => 'application/pdf'],
            ];

            if ($document->pdfTitle !== null) {
                $parts[] = ['name' => "documents[{$index}][pdfTitle]", 'contents' => $document->pdfTitle];
            }
        }

        return $parts;
    }
}
