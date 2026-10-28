<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

use DateTimeImmutable;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * An access token, and what came with it.
 */
final readonly class AccessToken
{
    /**
     * @param  list<string>  $scopes  as granted, which may be fewer than asked for (RFC 6749 section 3.3)
     */
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public ?DateTimeImmutable $expiresAt = null,
        #[SensitiveParameter]
        public ?string $refreshToken = null,
        public array $scopes = [],
    ) {}

    /**
     * Reads a token endpoint's successful response (RFC 6749 section 5.1).
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $requested  scopes asked for, which stand when the server doesn't list them
     * @param  string  $separator  how the server lists scopes, a space unless it says otherwise
     *
     * @throws OAuthException when there's no token, or it isn't a Bearer token
     */
    public static function fromResponse(array $data, ClockInterface $clock, array $requested = [], string $separator = ' '): self
    {
        $token = $data['access_token'] ?? null;
        $type = $data['token_type'] ?? null;

        if (! is_string($token) || $token === '' || ! is_string($type)) {
            throw new OAuthException('invalid_response', 'The token response has no access_token or token_type.');
        }

        // A client MUST NOT use a token whose type it doesn't understand
        // (section 7.1), and Bearer is the one the toolbox sends.
        if (strcasecmp($type, 'Bearer') !== 0) {
            throw new OAuthException('unsupported_token_type', "Only Bearer tokens are supported; the server issued [{$type}].");
        }

        $expiresIn = $data['expires_in'] ?? null;
        $scope = $data['scope'] ?? null;
        $refresh = $data['refresh_token'] ?? null;

        return new self(
            token: $token,
            expiresAt: is_int($expiresIn) || is_numeric($expiresIn) ? $clock->now()->modify(sprintf('+%d seconds', (int) $expiresIn)) : null,
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            scopes: is_string($scope) && $separator !== '' ? array_values(array_filter(array_map(trim(...), explode($separator, $scope)))) : $requested,
        );
    }

    /**
     * Expired, or close enough that a request sent now might arrive after.
     * A token with no known expiry is used until the API rejects it.
     */
    public function expired(ClockInterface $clock, int $leeway = 30): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->getTimestamp() - $leeway <= $clock->now()->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'token' => '[redacted]',
            'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
            'refreshToken' => $this->refreshToken === null ? null : '[redacted]',
            'scopes' => $this->scopes,
        ];
    }
}
