<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

/**
 * The request methods an SDK sends, with the properties RFC 9110 gives them.
 *
 * Safety and idempotency are what decide whether a request can be retried
 * without the caller's say-so (RFC 9110 section 9.2.2), so they live here
 * rather than in a retry policy that would have to guess.
 */
enum Method: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';

    /**
     * Safe methods are read-only by definition (RFC 9110 section 9.2.1).
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options => true,
            default => false,
        };
    }

    /**
     * Idempotent methods have the same effect sent once or several times
     * (RFC 9110 section 9.2.2): the safe methods, PUT and DELETE.
     */
    public function isIdempotent(): bool
    {
        return $this->isSafe() || $this === self::Put || $this === self::Delete;
    }
}
