<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;

/**
 * A response's content doesn't match its Content-Digest: it was changed or
 * cut short on the way.
 */
final class DigestMismatchException extends RuntimeException implements SdkException {}
