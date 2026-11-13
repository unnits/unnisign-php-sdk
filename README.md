# UnniSign PHP SDK

PHP 8.3+ client for creating UnniSign signing procedures. No Laravel dependency.

```sh
composer require unnits/unnisign-sdk
```

## Create a signing procedure

```php
<?php

require 'vendor/autoload.php';

use UnniSign\CreateSigningProcedure;
use UnniSign\Document;
use UnniSign\UnniSignClient;

$client = new UnniSignClient(
    baseUrl: 'https://sign.unni.cloud',
    accessToken: getenv('UNNISIGN_ACCESS_TOKEN') ?: '',
);

$procedure = $client->createSigningProcedure(new CreateSigningProcedure(
    documents: [
        Document::fromPath(
            uuid: '308c8e0a-dcc0-4db1-a340-d5fc7a17d771',
            path: './contract.pdf',
            pdfTitle: 'Contract',
        ),
    ],
    singleDeviceSign: true,
    webhookUrl: 'https://your-app.example/webhooks/unnisign',
));

// Store the procedure UUID and use signingLinks to direct your signers.
$procedureUuid = $procedure->uuid;
$signingLinks = $procedure->signingLinks;
```

`uuid` refers to an **existing document configured in the client's workspace**, not a new random UUID. Each file must be a valid PDF, at most 10 MB. Both callback URLs must match the host configured for the client in UnniSign. `baseUrl` is the deployment root, not the API URL.

For in-memory content, use `new Document($uuid, $pdfBytes, 'contract.pdf')`. Both input paths and file contents are loaded into memory.

### Optional fields

```php
$procedure = $client->createSigningProcedure(new CreateSigningProcedure(
    documents: $documents,
    singleDeviceSign: true,
    webhookUrl: 'https://your-app.example/webhooks/unnisign',
    headless: true,
    redirectUrl: 'https://your-app.example/finished',
    clientMetadata: ['orderId' => 'order-123'],
    clientHeaders: ['X-Trace-Id' => 'trace-123'],
    settings: [['name' => 'your-setting-name', 'value' => 'your-setting-value']],
    presetId: 42,
));
```

Here `$documents` is an array of `Document` objects as shown above; settings and presets must be valid for your deployment. JSON fields are encoded by the SDK; do not pre-encode them. `false` booleans are sent as `0`. Null options are omitted.

Headless signing requires certificates/settings configured on the server. It returns `202 Accepted` with an empty `signingLinks` array; this does not mean signing is complete.

The result is a readonly `SigningProcedure` with `uuid`, nullable `redirectUrl`, and `signingLinks`. Each readonly `SigningLink` has `value`, `expiresAt` (the API's timestamp string), and nullable `signer`.

### Errors and timeouts

```php
use GuzzleHttp\Exception\GuzzleException;
use UnniSign\Exception\ApiException;
use UnniSign\Exception\UnexpectedResponseException;

try {
    $procedure = $client->createSigningProcedure($request);
} catch (ApiException $exception) {
    // $exception->statusCode: HTTP status (e.g. 401, 422, 429).
    // $exception->responseBody: raw body, including Laravel validation details.
} catch (UnexpectedResponseException $exception) {
    // A successful HTTP response contained invalid JSON or an unexpected shape.
} catch (GuzzleException $exception) {
    // Network/TLS/timeout failure. Handle without blindly retrying creation.
}
```

`$request` is a `CreateSigningProcedure` object as shown above. Invalid local configuration/input raises `InvalidArgumentException`; unencodable metadata raises `JsonException`. Error bodies, Guzzle exceptions, and signing links can contain sensitive information; avoid logging them indiscriminately.

The default timeout is 30 seconds. Override it via `new UnniSignClient($baseUrl, $token, timeout: 60.0)`. An optional Guzzle `ClientInterface` can be injected via `httpClient` for custom networking or testing.

Requests are not automatically retried and redirects are not followed. A timed-out creation request may have succeeded already; retrying can create duplicates.

## Development

From this directory:

```sh
composer install
composer check
composer format
```

Checks include Mago 1.59.0 formatting/lint, PHPStan, and Pest. Publishing uses a split distribution repository registered with Packagist; see the monorepo README.
