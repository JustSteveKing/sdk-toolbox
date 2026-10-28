<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JsonException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Errors\TransportException;
use JustSteveKing\SdkToolbox\Http\QueryString;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * An OAuth 2.0 client registration: who the client is, and where the
 * authorisation server's endpoints are.
 *
 * It talks to the token endpoint directly over PSR-18, not through an SDK's
 * connector, because the authorisation server is usually another host
 * with its own rules. Every endpoint must be HTTPS (RFC 6749 section 3.2
 * requires TLS for the token endpoint) unless `insecure` says otherwise.
 */
final readonly class OAuthClient
{
    private ClientInterface $http;

    private RequestFactoryInterface $requests;

    private StreamFactoryInterface $streams;

    /**
     * @param  string|null  $clientSecret  null for a public client, such as a CLI or a mobile app
     * @param  string  $scopeSeparator  how the server lists scopes: a space, as RFC 6749 section 3.3 says, unless it uses commas
     */
    public function __construct(
        public string $tokenEndpoint,
        public string $clientId,
        #[SensitiveParameter]
        private ?string $clientSecret = null,
        public ?string $authorizationEndpoint = null,
        public ?string $deviceAuthorizationEndpoint = null,
        private ClientAuthentication $authentication = ClientAuthentication::Basic,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        public ClockInterface $clock = new SystemClock,
        bool $insecure = false,
        public string $scopeSeparator = ' ',
    ) {
        if ($scopeSeparator === '') {
            throw new InvalidRequestException('A scope separator can\'t be empty.');
        }

        foreach ([$tokenEndpoint, $authorizationEndpoint, $deviceAuthorizationEndpoint] as $endpoint) {
            if ($endpoint !== null && ! $insecure && ! str_starts_with(strtolower($endpoint), 'https://')) {
                throw new InvalidRequestException("OAuth endpoints must use TLS: [{$endpoint}]. Pass `insecure: true` for a local server.");
            }
        }

        $this->http = $http ?? Psr18ClientDiscovery::find();
        $this->requests = $requests ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streams = $streams ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * The `scope` parameter for a list of scopes, or null for none.
     *
     * @param  list<string>  $scopes
     */
    public function scope(array $scopes): ?string
    {
        return $scopes === [] ? null : implode($this->scopeSeparator, $scopes);
    }

    public function isPublic(): bool
    {
        return $this->clientSecret === null;
    }

    /**
     * POSTs a token request (RFC 6749 section 4) and reads the token.
     *
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $scopes  asked for, used when the response doesn't list what was granted
     *
     * @throws OAuthException when the server refuses
     * @throws TransportException when it can't be reached
     */
    public function requestToken(array $parameters, array $scopes = []): AccessToken
    {
        return AccessToken::fromResponse($this->post($this->tokenEndpoint, $parameters), $this->clock, $scopes, $this->scopeSeparator);
    }

    /**
     * POSTs a form to one of the server's endpoints, authenticated as this
     * client, and returns the decoded JSON.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<array-key, mixed>
     *
     * @throws OAuthException for an error response, or one that isn't JSON
     * @throws TransportException when the server can't be reached
     */
    public function post(string $endpoint, array $parameters): array
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'];

        if ($this->clientSecret !== null && $this->authentication === ClientAuthentication::Basic) {
            // The id and secret are form-encoded before they're joined and
            // base64-encoded (RFC 6749 section 2.3.1), which is the step
            // most clients miss and servers then reject secrets with a `+`.
            $headers['Authorization'] = 'Basic '.base64_encode(urlencode($this->clientId).':'.urlencode($this->clientSecret));
        } else {
            $parameters['client_id'] = $this->clientId;

            if ($this->clientSecret !== null) {
                $parameters['client_secret'] = $this->clientSecret;
            }
        }

        $request = $this->requests->createRequest('POST', $endpoint)
            ->withBody($this->streams->createStream(QueryString::build($parameters)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw TransportException::from($e, $request, new Redactor(query: ['code', 'code_verifier', 'device_code']));
        }

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OAuthException('invalid_response', "The server answered {$response->getStatusCode()} without JSON.", status: $response->getStatusCode(), previous: $e);
        }

        if (! is_array($data)) {
            throw new OAuthException('invalid_response', 'The server answered with JSON that isn\'t an object.', status: $response->getStatusCode());
        }

        if ($response->getStatusCode() >= 400 || isset($data['error'])) {
            // The error response of RFC 6749 section 5.2.
            throw new OAuthException(
                error: is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response',
                description: is_string($data['error_description'] ?? null) ? $data['error_description'] : null,
                uri: is_string($data['error_uri'] ?? null) ? $data['error_uri'] : null,
                status: $response->getStatusCode(),
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'tokenEndpoint' => $this->tokenEndpoint,
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret === null ? null : '[redacted]',
        ];
    }
}
