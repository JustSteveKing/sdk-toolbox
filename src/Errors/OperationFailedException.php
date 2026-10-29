<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;

/**
 * An asynchronous operation's monitor reported that it failed. The monitor's
 * own GET succeeded, so this is no HTTP error status: the problem is the one
 * the monitor described in its `error`.
 */
final class OperationFailedException extends RuntimeException implements SdkException
{
    public function __construct(
        public readonly string $monitor,
        public readonly ProblemDetails $problem,
    ) {
        parent::__construct(sprintf('The operation at %s failed: %s', $monitor, $problem->detail ?? $problem->title ?? $problem->type));
    }
}
