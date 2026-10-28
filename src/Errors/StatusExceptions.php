<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Redaction\Redactor;

/**
 * The default mapping: one exception class per status that a caller is
 * likely to handle differently, then ClientException or ServerException.
 */
final readonly class StatusExceptions implements ExceptionMapper
{
    public function map(Response $response, Redactor $redactor): HttpException
    {
        $class = match ($response->status()) {
            400 => BadRequestException::class,
            401 => UnauthorizedException::class,
            403 => ForbiddenException::class,
            404 => NotFoundException::class,
            409 => ConflictException::class,
            410 => GoneException::class,
            412 => PreconditionFailedException::class,
            422 => UnprocessableContentException::class,
            428 => PreconditionRequiredException::class,
            429 => TooManyRequestsException::class,
            default => $response->status() >= 500 ? ServerException::class : ClientException::class,
        };

        return new $class($response, $response->problem(), $redactor);
    }
}
