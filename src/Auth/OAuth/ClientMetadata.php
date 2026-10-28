<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth\OAuth;

/**
 * A client to register at runtime (RFC 7591 section 2): what it's called,
 * where it may redirect, which grants it uses, and how it authenticates.
 *
 * The defaults are the RFC's own. `grantTypes` is `authorization_code`
 * (section 2), so add `refresh_token` to refresh. `tokenEndpointAuthMethod`
 * is `client_secret_basic`; `none` makes a public client that relies on
 * PKCE. Anything the RFC or an extension defines that isn't a field here
 * goes in `extra`.
 */
final readonly class ClientMetadata
{
    /**
     * @param  list<string>  $redirectUris
     * @param  list<string>  $scopes  always sent space-separated, as section 2 defines `scope`
     * @param  list<string>  $grantTypes
     * @param  list<string>  $responseTypes
     * @param  list<string>  $contacts
     * @param  array<string, mixed>  $extra  other metadata, sent as top-level members
     */
    public function __construct(
        public array $redirectUris = [],
        public ?string $clientName = null,
        public ?string $clientUri = null,
        public ?string $logoUri = null,
        public array $scopes = [],
        public array $grantTypes = ['authorization_code'],
        public array $responseTypes = ['code'],
        public string $tokenEndpointAuthMethod = 'client_secret_basic',
        public array $contacts = [],
        public ?string $tosUri = null,
        public ?string $policyUri = null,
        public ?string $jwksUri = null,
        public ?string $softwareId = null,
        public ?string $softwareVersion = null,
        public array $extra = [],
    ) {}

    /**
     * The registration request's JSON object (section 3.1).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->extra,
            ...array_filter([
                'redirect_uris' => $this->redirectUris === [] ? null : $this->redirectUris,
                'client_name' => $this->clientName,
                'client_uri' => $this->clientUri,
                'logo_uri' => $this->logoUri,
                'scope' => $this->scopes === [] ? null : implode(' ', $this->scopes),
                'grant_types' => $this->grantTypes,
                'response_types' => $this->responseTypes,
                'token_endpoint_auth_method' => $this->tokenEndpointAuthMethod,
                'contacts' => $this->contacts === [] ? null : $this->contacts,
                'tos_uri' => $this->tosUri,
                'policy_uri' => $this->policyUri,
                'jwks_uri' => $this->jwksUri,
                'software_id' => $this->softwareId,
                'software_version' => $this->softwareVersion,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
