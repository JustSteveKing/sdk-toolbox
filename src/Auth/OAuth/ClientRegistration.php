<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JsonException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Registers a client at runtime (RFC 7591), for software that can't be
 * registered by hand ahead of time: one client per installation, an agent,
 * a multi-tenant integration.
 *
 *     $client = (new ClientRegistration('https://auth.acme.test/register'))
 *         ->register(new ClientMetadata(['https://app.test/callback'], 'My App', grantTypes: ['authorization_code', 'refresh_token']));
 *
 *     $oauth = $client->oauthClient('https://auth.acme.test/oauth/token', 'https://auth.acme.test/oauth/authorize');
 *
 * The metadata goes as JSON (section 3.1), with the initial access token
 * as a Bearer token when the server requires one. A refusal (section
 * 3.2.2, such as `invalid_redirect_uri`) throws OAuthException. The
 * endpoint must be HTTPS, unless `insecure` says otherwise.
 */
final readonly class ClientRegistration
{
    private ClientInterface $http;

    private RequestFactoryInterface $requests;

    private StreamFactoryInterface $streams;

    public function __construct(
        public string $registrationEndpoint,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        bool $insecure = false,
    ) {
        if (! $insecure && ! str_starts_with(strtolower($registrationEndpoint), 'https://')) {
            throw new InvalidRequestException("OAuth endpoints must use TLS: [{$registrationEndpoint}]. Pass `insecure: true` for a local server.");
        }

        $this->http = $http ?? Psr18ClientDiscovery::find();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @throws OAuthException when the server refuses, or answers without a client_id
     * @throws TransportException when it can't be reached
     */
    public function register(ClientMetadata $metadata, #[SensitiveParameter] ?string $initialAccessToken = null): RegisteredClient
    {
        $request = $this->requests->createRequest('POST', $this->registrationEndpoint)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($metadata->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        if ($initialAccessToken !== null) {
            $request = $request->withHeader('Authorization', "Bearer {$initialAccessToken}");
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw TransportException::from($e, $request, new Redactor);
        }

        $status = $response->getStatusCode();

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OAuthException('invalid_response', "The registration endpoint answered {$status} without JSON.", status: $status, previous: $e);
        }

        if (! is_array($data)) {
            throw new OAuthException('invalid_response', 'The registration endpoint answered with JSON that isn\'t an object.', status: $status);
        }

        if ($status >= 400) {
            throw new OAuthException(
                error: is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response',
                description: is_string($data['error_description'] ?? null) ? $data['error_description'] : null,
                status: $status,
            );
        }

        return RegisteredClient::fromResponse($data);
    }
}
