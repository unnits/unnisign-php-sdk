<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use UnniSign\CreateSigningProcedure;
use UnniSign\Document;
use UnniSign\Exception\ApiException;
use UnniSign\Exception\UnexpectedResponseException;
use UnniSign\UnniSignClient;

function procedure_json(): string
{
    return json_encode([
        'uuid' => 'e9709f80-a33b-4b5e-a1a6-c2cad08466e3',
        'redirect_url' => 'https://sign.example.test/sign',
        'signing_links' => [
            ['value' => 'https://sign.example.test/sign?token=secret', 'expires_at' => '2026-12-01T12:00:00Z'],
            [
                'value' => 'https://sign.example.test/sign?token=next',
                'expires_at' => '2026-12-01T12:00:00Z',
                'signer' => 'Jane',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

function procedure_input(): CreateSigningProcedure
{
    return new CreateSigningProcedure(
        documents: [new Document('308c8e0a-dcc0-4db1-a340-d5fc7a17d771', "%PDF-1.7\nbinary\0data", 'contract.pdf')],
        singleDeviceSign: true,
        webhookUrl: 'https://client.example.test/webhook',
    );
}

it('sends authenticated multipart PDF uploads and returns typed signing links', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], procedure_json())]));
    $stack->push(Middleware::history($history));
    $client = new UnniSignClient(
        baseUrl: 'https://sign.example.test/',
        accessToken: 'test-token',
        httpClient: new Client(['handler' => $stack]),
    );

    $result = $client->createSigningProcedure(procedure_input());
    $request = $history[0]['request'];
    $body = (string) $request->getBody();

    expect($request->getMethod())
        ->toBe('POST')
        ->and((string) $request->getUri())
        ->toBe('https://sign.example.test/api/v1/signing-procedures')
        ->and($request->getHeaderLine('Authorization'))
        ->toBe('Bearer test-token')
        ->and($request->getHeaderLine('Accept'))
        ->toBe('application/json')
        ->and($request->getHeaderLine('Content-Type'))
        ->toContain('multipart/form-data; boundary=')
        ->and($body)
        ->toContain('name="single_device_sign"', "\r\n\r\n1\r\n", 'name="documents[0][uuid]"')
        ->and($body)
        ->toContain('filename="contract.pdf"', 'Content-Type: application/pdf', "%PDF-1.7\nbinary\0data")
        ->and($body)
        ->not
        ->toContain('name="headless"', 'name="client_metadata"')
        ->and($history[0]['options']['timeout'])
        ->toBe(30.0)
        ->and($history[0]['options']['allow_redirects'])
        ->toBeFalse()
        ->and($result->uuid)
        ->toBe('e9709f80-a33b-4b5e-a1a6-c2cad08466e3')
        ->and($result->redirectUrl)
        ->toBe('https://sign.example.test/sign')
        ->and($result->signingLinks)
        ->toHaveCount(2)
        ->and($result->signingLinks[0]->expiresAt)
        ->toBe('2026-12-01T12:00:00Z')
        ->and($result->signingLinks[0]->signer)
        ->toBeNull()
        ->and($result->signingLinks[1]->signer)
        ->toBe('Jane');
});

it('serializes optional fields, multiple documents, and false booleans', function (): void {
    $input = new CreateSigningProcedure(
        documents: [
            new Document('first-uuid', '%PDF-first', 'first.pdf', 'Smlouva — český název'),
            new Document('second-uuid', '%PDF-second', 'second.pdf'),
        ],
        singleDeviceSign: false,
        webhookUrl: 'https://client.example.test/webhook',
        headless: false,
        redirectUrl: 'https://client.example.test/return',
        clientMetadata: ['order' => 42, 'nested' => [true, null]],
        clientHeaders: ['X-Trace-Id' => 'trace'],
        settings: [['name' => 'locale', 'value' => 'cs']],
        presetId: 42,
    );
    $parts = array_column($input->toMultipart(), 'contents', 'name');

    expect($parts['single_device_sign'])
        ->toBe('0')
        ->and($parts['headless'])
        ->toBe('0')
        ->and($parts['redirect_url'])
        ->toBe('https://client.example.test/return')
        ->and($parts['client_metadata'])
        ->toBe('{"order":42,"nested":[true,null]}')
        ->and($parts['client_headers'])
        ->toBe('{"X-Trace-Id":"trace"}')
        ->and($parts['settings'])
        ->toBe('[{"name":"locale","value":"cs"}]')
        ->and($parts['preset_id'])
        ->toBe('42')
        ->and($parts['documents[0][pdfTitle]'])
        ->toBe('Smlouva — český název')
        ->and($parts['documents[1][uuid]'])
        ->toBe('second-uuid')
        ->and($parts['documents[1][file]'])
        ->toBe('%PDF-second')
        ->and($parts)
        ->not->toHaveKey('documents[1][pdfTitle]');
});

it('accepts a 202 headless response with empty signing links and a null redirect', function (): void {
    $history = [];
    $body = '{"uuid":"procedure-uuid","redirect_url":null,"signing_links":[]}';
    $stack = HandlerStack::create(new MockHandler([new Response(202, [], $body)]));
    $stack->push(Middleware::history($history));
    $client = new UnniSignClient('https://sign.example.test', 'token', httpClient: new Client(['handler' => $stack]));
    $input = new CreateSigningProcedure(
        procedure_input()->documents,
        true,
        'https://client.example.test/webhook',
        headless: true,
    );

    $result = $client->createSigningProcedure($input);

    expect($result->uuid)
        ->toBe('procedure-uuid')
        ->and($result->redirectUrl)
        ->toBeNull()
        ->and($result->signingLinks)
        ->toBe([])
        ->and((string) $history[0]['request']->getBody())
        ->toContain("name=\"headless\"\r\nContent-Length: 1\r\n\r\n1");
});

it('preserves explicitly empty JSON collections', function (): void {
    $input = new CreateSigningProcedure(
        procedure_input()->documents,
        true,
        'https://client.example.test/webhook',
        clientMetadata: [],
        clientHeaders: [],
        settings: [],
    );
    $parts = array_column($input->toMultipart(), 'contents', 'name');

    expect($parts['client_metadata'])
        ->toBe('[]')
        ->and($parts['client_headers'])
        ->toBe('[]')
        ->and($parts['settings'])
        ->toBe('[]');
});

it('exposes API errors without retrying creation', function (int $status): void {
    $body = '{"message":"Validation failed","errors":{"documents.0.uuid":["Unknown document"]}}';
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));
    $stack->push(Middleware::history($history));
    $client = new UnniSignClient('https://sign.example.test', 'token', httpClient: new Client(['handler' => $stack]));

    try {
        $client->createSigningProcedure(procedure_input());
        test()->fail('Expected an API exception.');
    } catch (ApiException $exception) {
        expect($exception->statusCode)
            ->toBe($status)
            ->and($exception->responseBody)
            ->toBe($body)
            ->and($history)
            ->toHaveCount(1);
    }
})->with([301, 401, 403, 422, 429, 500]);

it('preserves non-JSON API errors', function (): void {
    $mock = new MockHandler([new Response(502, [], '<html>Gateway unavailable</html>')]);
    $client = new UnniSignClient('https://sign.example.test', 'token', httpClient: new Client([
        'handler' => HandlerStack::create($mock),
    ]));

    expect(fn() => $client->createSigningProcedure(procedure_input()))->toThrow(ApiException::class, 'HTTP 502');
});

it('rejects malformed successful responses', function (string $body): void {
    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new UnniSignClient('https://sign.example.test', 'token', httpClient: new Client([
        'handler' => HandlerStack::create($mock),
    ]));

    expect(fn() => $client->createSigningProcedure(procedure_input()))->toThrow(UnexpectedResponseException::class);
})->with([
    'not JSON',
    'null',
    '[]',
    '{}',
    '{"uuid":"uuid","signing_links":[]}',
    '{"uuid":123,"redirect_url":null,"signing_links":[]}',
    '{"uuid":"uuid","redirect_url":null,"signing_links":"wrong"}',
    '{"uuid":"uuid","redirect_url":null,"signing_links":{}}',
    '{"uuid":"uuid","redirect_url":null,"signing_links":[{"value":"url"}]}',
    '{"uuid":"uuid","redirect_url":null,"signing_links":[{"value":"url","expires_at":"date","signer":null}]}',
]);

it('propagates transport failures without retrying', function (): void {
    $error = new ConnectException('Connection failed', new Request('POST', 'https://sign.example.test'));
    $mock = new MockHandler([$error]);
    $client = new UnniSignClient('https://sign.example.test', 'token', httpClient: new Client([
        'handler' => HandlerStack::create($mock),
    ]));

    expect(fn() => $client->createSigningProcedure(
        procedure_input(),
    ))->toThrow(ConnectException::class)->and($mock->count())->toBe(0);
});

it('requires at least one document', function (): void {
    expect(fn() => new CreateSigningProcedure([], true, 'https://client.example.test/webhook'))
        ->toThrow(InvalidArgumentException::class, 'At least one document');
});

it('rejects invalid deployment roots', function (string $baseUrl): void {
    expect(fn() => new UnniSignClient($baseUrl, 'token'))->toThrow(InvalidArgumentException::class);
})->with([
    'not a URL',
    'ftp://example.test',
    'https://user:pass@example.test',
    'https://user@example.test',
    'https://example.test?token=secret',
    'https://example.test#fragment',
]);

it('rejects invalid access tokens', function (string $token): void {
    expect(fn() => new UnniSignClient('https://sign.example.test', $token))->toThrow(InvalidArgumentException::class);
})->with(['', ' ', "token\r\ninjected"]);

it('rejects invalid timeouts', function (float $timeout): void {
    expect(fn() => new UnniSignClient('https://sign.example.test', 'token', timeout: $timeout))
        ->toThrow(InvalidArgumentException::class);
})->with([0.0, -1.0, INF, NAN]);

it('supports deployment paths and custom timeouts', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], procedure_json())]));
    $stack->push(Middleware::history($history));
    $client = new UnniSignClient('https://example.test/signing/', 'token', timeout: 5.0, httpClient: new Client([
        'handler' => $stack,
    ]));

    $client->createSigningProcedure(procedure_input());

    expect((string) $history[0]['request']->getUri())
        ->toBe('https://example.test/signing/api/v1/signing-procedures')
        ->and($history[0]['options']['timeout'])
        ->toBe(5.0);
});

it('loads a local PDF file without changing its bytes', function (): void {
    $path = tempnam(sys_get_temp_dir(), prefix: 'unnisign-');
    file_put_contents($path, data: "%PDF-1.7\nbinary\0data");

    try {
        $document = Document::fromPath('document-uuid', $path, 'Contract');

        expect($document->uuid)
            ->toBe('document-uuid')
            ->and($document->contents)
            ->toBe("%PDF-1.7\nbinary\0data")
            ->and($document->filename)
            ->toBe(basename($path))
            ->and($document->pdfTitle)
            ->toBe('Contract');
    } finally {
        unlink($path);
    }
});

it('rejects missing files, directories, and remote file URLs', function (string $path): void {
    expect(fn() => Document::fromPath('document-uuid', $path))->toThrow(InvalidArgumentException::class);
})->with(['/nonexistent/contract.pdf', __DIR__, 'https://example.test/contract.pdf']);
