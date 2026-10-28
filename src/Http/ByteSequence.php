<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

/**
 * A Structured Field byte sequence (RFC 9651 section 3.3.5), decoded:
 * `:aGk=:` is the two bytes `hi`. Kept apart from strings so a digest or
 * a signature can't be confused with text.
 */
final readonly class ByteSequence
{
    public function __construct(
        public string $bytes,
    ) {}
}
