<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use LogicException;
use Throwable;

/**
 * No PSR-18 client, or no PSR-17 factory, is installed, so a connector
 * built without one can't send anything.
 *
 * `composer require` on an SDK succeeds without one, because
 * `php-http/discovery` declares that it provides the PSR-18 and PSR-17
 * implementations. Its Composer plugin would install a client, but only
 * when the plugin is allowed, and a non-interactive install (CI, a Docker
 * build) never asks. So the first sign used to be discovery's own
 * exception, from deep inside the first constructor call. This says what
 * to install instead.
 *
 * It's a LogicException: the application is missing a package, and no
 * retry will fix that.
 */
final class NoHttpClientException extends LogicException implements SdkException
{
    public static function missing(string $what, Throwable $previous): self
    {
        return new self(
            "No {$what} is installed, so the connector can't send requests. Install one with `composer require guzzlehttp/guzzle`, or `composer require symfony/http-client nyholm/psr7`, or pass your own to the connector. (php-http/discovery's Composer plugin would install one for you, but only when it's allowed to run.)",
            previous: $previous,
        );
    }
}
