<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Auth;

use Psr\Http\Message\RequestInterface;

/**
 * Adds credentials to a request.
 *
 * Runs last, just before the HTTP client, so every attempt of a retried
 * request is authenticated afresh, and nothing earlier in the pipeline,
 * a logger included, sees the credentials.
 */
interface Authenticator
{
    public function authenticate(RequestInterface $request): RequestInterface;

    /**
     * Header and query parameter names this authenticator writes secrets
     * into, beyond the ones Redactor already masks.
     *
     * @return array{headers: list<string>, query: list<string>}
     */
    public function sensitive(): array;
}
