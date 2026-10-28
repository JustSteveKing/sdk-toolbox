<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Deprecation\DeprecationNotice;
use RuntimeException;

/**
 * Thrown by Deprecations::strict(): for an SDK's own test suite, so a
 * deprecated endpoint fails the build instead of a log line nobody reads.
 */
final class DeprecatedEndpointException extends RuntimeException implements SdkException
{
    public function __construct(
        public readonly DeprecationNotice $notice,
    ) {
        parent::__construct($notice->describe());
    }
}
