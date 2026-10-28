<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;

/**
 * A signature that's missing, malformed, expired, made with the wrong key,
 * or simply doesn't match. Treat the message as unauthenticated.
 */
final class InvalidSignatureException extends RuntimeException implements SdkException {}
