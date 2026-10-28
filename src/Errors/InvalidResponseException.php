<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use JustSteveKing\SdkToolbox\Http\Response;
use RuntimeException;
use Throwable;

/**
 * A response that came back but can't be read as the request expects.
 */
final class InvalidResponseException extends RuntimeException implements SdkException
{
    public static function notJson(Response $response, ?Throwable $previous = null): self
    {
        $path = $response->request()->getUri()->getPath();

        return new self(
            "{$response->request()->getMethod()} {$path} returned {$response->status()} with a body that isn't a JSON object or array.",
            previous: $previous,
        );
    }
}
