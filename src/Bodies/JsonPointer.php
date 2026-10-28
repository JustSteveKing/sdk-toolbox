<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;

/**
 * RFC 6901 JSON Pointers, for JSON Patch paths.
 */
final class JsonPointer
{
    /**
     * Builds a pointer from raw segments, escaping `~` and `/` in each.
     */
    public static function from(string|int ...$segments): string
    {
        return implode('', array_map(
            static fn (string|int $segment): string => '/'.str_replace(['~', '/'], ['~0', '~1'], (string) $segment),
            $segments,
        ));
    }

    /**
     * @throws InvalidRequestException when the pointer isn't valid RFC 6901
     */
    public static function assertValid(string $pointer): string
    {
        // Empty is the whole document; otherwise every segment starts with
        // `/`, and `~` is only ever `~0` or `~1` (RFC 6901 section 3).
        if ($pointer !== '' && (! str_starts_with($pointer, '/') || preg_match('/~(?![01])/', $pointer) === 1)) {
            throw new InvalidRequestException("Not a valid JSON Pointer (RFC 6901): [{$pointer}]. Build one with JsonPointer::from().");
        }

        return $pointer;
    }
}
