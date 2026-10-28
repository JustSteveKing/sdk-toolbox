<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Redaction\Redactor;

/**
 * Maps an API's problem types to an SDK's own exceptions.
 *
 * RFC 9457 makes `type` the problem's identifier (section 3.1.1), so that's
 * what is matched, never `title` or `detail`. Types are matched once
 * resolved to absolute URIs, as the API documents them. Anything
 * unmatched falls through to the status mapping.
 */
final readonly class ProblemTypes implements ExceptionMapper
{
    /**
     * @param  array<string, class-string<HttpException>>  $types
     */
    public function __construct(
        private array $types,
        private ExceptionMapper $fallback = new StatusExceptions,
    ) {}

    public function map(Response $response, Redactor $redactor): HttpException
    {
        $problem = $response->problem();
        $class = $problem === null ? null : ($this->types[$problem->type] ?? null);

        return $class === null
            ? $this->fallback->map($response, $redactor)
            : new $class($response, $problem, $redactor);
    }
}
