<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use Closure;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Redaction\Redactor;

/**
 * The default mapping: one exception class per status that a caller is
 * likely to handle differently, then ClientException or ServerException.
 *
 * The exception carries the response's RFC 9457 problem. Most APIs answer
 * errors in a shape of their own instead (`{"error": "..."}`, an `errors`
 * array), so `problems` reads that shape into a ProblemDetails, and the
 * exception carries it the same way:
 *
 *     new StatusExceptions(problems: fn (Response $r): ?ProblemDetails => ...)
 *
 * When the reader returns null, the RFC 9457 problem is used, if there is one.
 */
final readonly class StatusExceptions implements ExceptionMapper
{
    /**
     * @param  (Closure(Response): ?ProblemDetails)|null  $problems  reads an API's own error shape
     */
    public function __construct(
        private ?Closure $problems = null,
    ) {}

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

        $problem = $this->problems === null ? null : ($this->problems)($response);

        return new $class($response, $problem ?? $response->problem(), $redactor);
    }
}
