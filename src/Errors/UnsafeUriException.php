<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

/**
 * A base URI or request path that would send a request somewhere it
 * shouldn't go. Nothing was sent.
 */
final class UnsafeUriException extends InvalidRequestException {}
