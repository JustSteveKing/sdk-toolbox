<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

use JustSteveKing\SdkToolbox\Errors\SdkException;
use LogicException;

/**
 * The SDK sent something other than what the fixture recorded, so its
 * recorded answer can't stand in. Re-record, or fix the SDK.
 */
final class FixtureMismatchException extends LogicException implements SdkException {}
