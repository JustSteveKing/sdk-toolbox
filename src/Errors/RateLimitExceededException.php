<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;

/**
 * A Throttle refused to send a request because the quota is known to be
 * spent, and the reset is further off than it was allowed to wait.
 * Nothing was sent.
 */
final class RateLimitExceededException extends RuntimeException implements SdkException
{
    public function __construct(
        public readonly float $retryAfter,
    ) {
        parent::__construct(sprintf('The rate limit is spent for another %.1f seconds; not sending.', $retryAfter));
    }
}
