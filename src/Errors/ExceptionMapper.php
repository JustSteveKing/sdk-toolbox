<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Redaction\Redactor;

/**
 * Turns an error response into the exception an SDK throws for it.
 */
interface ExceptionMapper
{
    public function map(Response $response, Redactor $redactor): HttpException;
}
