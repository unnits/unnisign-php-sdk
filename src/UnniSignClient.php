<?php

declare(strict_types=1);

namespace UnniSign;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use SensitiveParameter;
use UnniSign\Exception\ApiException;

final readonly class UnniSignClient
{
    private ClientInterface $httpClient;
    private string $endpoint;

    public function __construct(
        string $baseUrl,
        #[SensitiveParameter]
        private string $accessToken,
        private float $timeout = 30.0,
        ?ClientInterface $httpClient = null,
    ) {
        $url = parse_url($baseUrl);

        if (
            $url === false
            || !in_array($url['scheme'] ?? '', ['http', 'https'], strict: true)
            || ($url['host'] ?? '') === ''
            || array_key_exists('user', $url)
            || array_key_exists('query', $url)
            || array_key_exists('fragment', $url)
        ) {
            throw new InvalidArgumentException(
                'baseUrl must be an HTTP(S) deployment root without credentials, query, or fragment.',
            );
        }

        if (trim($accessToken) === '' || strpbrk($accessToken, characters: "\r\n") !== false) {
            throw new InvalidArgumentException('accessToken must be non-empty and contain no newlines.');
        }

        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('timeout must be a positive number of seconds.');
        }

        $this->endpoint = rtrim($baseUrl, characters: '/') . '/api/v1/signing-procedures';
        $this->httpClient = $httpClient ?? new Client();
    }

    public function createSigningProcedure(CreateSigningProcedure $input): SigningProcedure
    {
        $response = $this->httpClient->request('POST', $this->endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/json',
            ],
            'multipart' => $input->toMultipart(),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new ApiException($status, $body);
        }

        return SigningProcedure::fromJson($body);
    }
}
