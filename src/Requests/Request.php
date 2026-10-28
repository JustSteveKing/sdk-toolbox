<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Requests;

use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Response;

/**
 * One API operation: what to send, and what a successful answer becomes.
 *
 * `result()` is only called for a 2xx or 3xx response; error statuses are
 * thrown before it. Its return type is the request's template type, so
 * `$connector->send(new GetApplication($id))` is an `Application` to
 * PHPStan as well as at runtime.
 *
 * Query, headers and a body are optional, through HasQuery, HasHeaders and
 * HasBody, so a request only says what it uses.
 *
 * @template-covariant TResult
 */
interface Request
{
    public function method(): Method;

    /**
     * The path below the connector's base URI, such as `/applications/42`.
     */
    public function path(): string;

    /**
     * @return TResult
     */
    public function result(Response $response): mixed;
}
