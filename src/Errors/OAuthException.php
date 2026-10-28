<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;
use Throwable;

/**
 * The authorisation server said no, or said something a client can't use.
 *
 * `error` is the RFC 6749 section 5.2 code (`invalid_client`,
 * `invalid_grant`, `invalid_scope`...) when the server sent one, or the
 * toolbox's own for problems it found itself (`invalid_response`,
 * `unsupported_token_type`, `no_refresh_token`, `expired_token`).
 */
final class OAuthException extends RuntimeException implements SdkException
{
    public function __construct(
        public readonly string $error,
        public readonly ?string $description = null,
        public readonly ?string $uri = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($description === null ? "OAuth error: {$error}" : "OAuth error: {$error}: {$description}", previous: $previous);
    }
}
