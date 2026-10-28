<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;

/**
 * An asynchronous operation was still running when await() gave up. It
 * may well finish: the monitor URI is kept so you can look again later.
 */
final class OperationTimeoutException extends RuntimeException implements SdkException
{
    public function __construct(
        public readonly string $monitor,
        public readonly float $waited,
    ) {
        parent::__construct(sprintf('The operation was still running after %.0f seconds; its status is at %s.', $waited, $monitor));
    }
}
