<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use Throwable;

/**
 * Every exception the toolbox throws, so an SDK's callers can catch one type.
 */
interface SdkException extends Throwable {}
