<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use InvalidArgumentException;

/**
 * A request that can't be built as given. Nothing was sent.
 */
class InvalidRequestException extends InvalidArgumentException implements SdkException {}
