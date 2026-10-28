<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use DateTimeImmutable;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Time\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * What a registration endpoint issued (RFC 7591 section 3.2.1): the client
 * ID, a secret if the client is confidential, and every piece of metadata
 * the server registered, which may differ from what was asked for.
 *
 * The secret, and the registration access token RFC 7592 adds for managing
 * the registration later, are credentials: store them as you would any
 * other secret. Both are kept out of dumps.
 */
final readonly class RegisteredClient
{
    /**
     * @param  array<string, mixed>  $metadata  everything the server registered, as it sent it
     */
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        private ?string $clientSecret = null,
        public ?DateTimeImmutable $clientIdIssuedAt = null,
        public ?DateTimeImmutable $clientSecretExpiresAt = null,
        #[SensitiveParameter]
        private ?string $registrationAccessToken = null,
        public ?string $registrationClientUri = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws OAuthException when there's no client_id, which section 3.2.1 requires
     */
    public static function fromResponse(array $data): self
    {
        $id = $data['client_id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new OAuthException('invalid_response', 'The registration response has no client_id.');
        }

        $time = static fn (mixed $value): ?DateTimeImmutable => is_int($value) && $value > 0 ? new DateTimeImmutable("@{$value}") : null;
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        $metadata = [];

        foreach ($data as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        unset($metadata['client_secret'], $metadata['registration_access_token']);

        return new self(
            clientId: $id,
            clientSecret: $string($data['client_secret'] ?? null),
            clientIdIssuedAt: $time($data['client_id_issued_at'] ?? null),
            // 0 means the secret never expires (section 3.2.1).
            clientSecretExpiresAt: $time($data['client_secret_expires_at'] ?? null),
            registrationAccessToken: $string($data['registration_access_token'] ?? null),
            registrationClientUri: $string($data['registration_client_uri'] ?? null),
            metadata: $metadata,
        );
    }

    public function clientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function registrationAccessToken(): ?string
    {
        return $this->registrationAccessToken;
    }

    /**
     * The token endpoint authentication method the server registered,
     * which may not be the one asked for.
     */
    public function tokenEndpointAuthMethod(): string
    {
        $method = $this->metadata['token_endpoint_auth_method'] ?? null;

        return is_string($method) ? $method : 'client_secret_basic';
    }

    public function isPublic(): bool
    {
        return $this->clientSecret === null;
    }

    /**
     * An OAuthClient for this registration, authenticating the way the
     * server registered it to: Basic for `client_secret_basic`, the body
     * for `client_secret_post`, nothing for a public client.
     *
     * @throws OAuthException when the server registered a method the toolbox can't use, such as `private_key_jwt`
     */
    public function oauthClient(
        string $tokenEndpoint,
        ?string $authorizationEndpoint = null,
        ?string $deviceAuthorizationEndpoint = null,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        ?ClockInterface $clock = null,
        string $scopeSeparator = ' ',
    ): OAuthClient {
        $authentication = match ($this->tokenEndpointAuthMethod()) {
            'client_secret_basic', 'none' => ClientAuthentication::Basic,
            'client_secret_post' => ClientAuthentication::Post,
            default => throw new OAuthException('unsupported_auth_method', "The server registered [{$this->tokenEndpointAuthMethod()}], which the toolbox doesn't support."),
        };

        return new OAuthClient(
            tokenEndpoint: $tokenEndpoint,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            authorizationEndpoint: $authorizationEndpoint,
            deviceAuthorizationEndpoint: $deviceAuthorizationEndpoint,
            authentication: $authentication,
            http: $http,
            requests: $requests,
            streams: $streams,
            clock: $clock ?? new SystemClock,
            scopeSeparator: $scopeSeparator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret === null ? null : '[redacted]',
            'registrationAccessToken' => $this->registrationAccessToken === null ? null : '[redacted]',
            'registrationClientUri' => $this->registrationClientUri,
            'metadata' => $this->metadata,
        ];
    }
}
