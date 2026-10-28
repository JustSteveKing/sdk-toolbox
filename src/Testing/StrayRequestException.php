<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

use JustSteveKing\SdkToolbox\Errors\SdkException;
use LogicException;

/**
 * A test sent a request the fake had no answer for.
 *
 * Deliberately not a PSR-18 ClientExceptionInterface: the connector would
 * turn that into a TransportException, which an SDK might catch and retry,
 * and the test would pass for the wrong reason.
 */
final class StrayRequestException extends LogicException implements SdkException {}
