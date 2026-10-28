<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use DateTimeImmutable;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;

/**
 * A conditional request's precondition (RFC 9110 section 13.1).
 *
 * The everyday use is the lost-update guard: read a resource, keep its
 * ETag, and send the change with `If-Match`, so it only applies if nobody
 * changed the resource in between. A 412 says somebody did.
 *
 *     Precondition::ifMatch($response->etag())   // update only if unchanged
 *     Precondition::ifNoneMatchAny()              // create only if it doesn't exist
 */
final readonly class Precondition
{
    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public array $headers,
    ) {}

    /**
     * `If-Match`, which uses strong comparison (section 13.1.1): a weak tag
     * can never match, so sending one would fail every time, and is refused.
     */
    public static function ifMatch(EntityTag|string ...$tags): self
    {
        if ($tags === []) {
            throw new InvalidRequestException('If-Match needs at least one entity tag.');
        }

        $parsed = array_map(self::tag(...), $tags);

        foreach ($parsed as $tag) {
            if ($tag->weak) {
                throw new InvalidRequestException("If-Match uses strong comparison, so the weak tag [{$tag}] could never match (RFC 9110 section 13.1.1).");
            }
        }

        return new self(['If-Match' => implode(', ', array_map(strval(...), $parsed))]);
    }

    /**
     * `If-Match: *`: only if the resource exists.
     */
    public static function ifExists(): self
    {
        return new self(['If-Match' => '*']);
    }

    /**
     * `If-None-Match: *`: only if the resource doesn't exist yet, so a PUT
     * creates without overwriting (section 13.1.2).
     */
    public static function ifNoneMatchAny(): self
    {
        return new self(['If-None-Match' => '*']);
    }

    public static function ifUnmodifiedSince(DateTimeImmutable $date): self
    {
        return new self(['If-Unmodified-Since' => HttpDate::format($date)]);
    }

    private static function tag(EntityTag|string $tag): EntityTag
    {
        if ($tag instanceof EntityTag) {
            return $tag;
        }

        return EntityTag::parse($tag) ?? throw new InvalidRequestException("Not a valid entity tag: [{$tag}]. Pass the ETag header as it came, quotes included.");
    }
}
