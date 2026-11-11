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

    /**
     * The mapping, reading an API's flat JSON error by field name rather
     * than with a closure, for the shape most APIs use:
     *
     *     // {"statusCode": 422, "name": "validation_error", "message": "..."}
     *     StatusExceptions::fromFields(title: 'name', detail: 'message')
     *
     *     // {"error": "...", "error_code": 477, "error_extra": {"event_id": "..."}}
     *     StatusExceptions::fromFields(title: 'error', extensions: [
     *         'code' => 'error_code',
     *         'event_id' => 'error_extra.event_id',
     *     ])
     *
     * Paths are dotted, as Response::get() reads them. `title` is the field
     * that marks a body as one of the API's errors: when it's missing, or
     * not a string, or the body isn't JSON, nothing is read and the RFC 9457
     * problem is used, if there is one. `detail` and `type` are kept when
     * they're strings; each extension is kept as it was sent, or null.
     * Read them back from the exception with `$e->problem->title` and
     * `$e->problem->extension('code')`. Anything stranger than this still
     * takes a closure.
     *
     * @param  array<string, string>  $extensions  extension name => dotted path in the body
     */
    public static function fromFields(string $title, ?string $detail = null, ?string $type = null, array $extensions = []): self
    {
        return new self(problems: static function (Response $response) use ($title, $detail, $type, $extensions): ?ProblemDetails {
            try {
                $titled = $response->get($title);
            } catch (InvalidResponseException) {
                return null;
            }

            if (! is_string($titled)) {
                return null;
            }

            $detailed = $detail === null ? null : $response->get($detail);
            $typed = $type === null ? null : $response->get($type);

            return new ProblemDetails(
                type: is_string($typed) && $typed !== '' ? $typed : ProblemDetails::BLANK,
                title: $titled,
                status: $response->status(),
                detail: is_string($detailed) ? $detailed : null,
                extensions: array_map(static fn (string $path): mixed => $response->get($path), $extensions),
            );
        });
    }

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
