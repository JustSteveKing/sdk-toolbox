<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Stringable;

/**
 * An entity tag (RFC 9110 section 8.8.3): `"xyzzy"` or weak `W/"xyzzy"`.
 */
final readonly class EntityTag implements Stringable
{
    public function __construct(
        public string $opaque,
        public bool $weak = false,
    ) {
        // etagc = %x21 / %x23-7E / obs-text: no quotes, spaces or controls.
        if (preg_match('/^[\x21\x23-\x7E\x80-\xFF]*$/', $opaque) !== 1) {
            throw new InvalidRequestException("Not a valid entity tag: [{$opaque}].");
        }
    }

    public static function parse(string $value): ?self
    {
        if (preg_match('/^\s*(W\/)?"([\x21\x23-\x7E\x80-\xFF]*)"\s*$/', $value, $match) !== 1) {
            return null;
        }

        return new self($match[2], $match[1] !== '');
    }

    /**
     * Strong comparison (section 8.8.3.2): both strong, same opaque value.
     */
    public function matchesStrongly(self $other): bool
    {
        return ! $this->weak && ! $other->weak && $this->opaque === $other->opaque;
    }

    public function __toString(): string
    {
        return ($this->weak ? 'W/' : '').'"'.$this->opaque.'"';
    }
}
