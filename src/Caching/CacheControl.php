<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Caching;

use Psr\Http\Message\MessageInterface;

/**
 * `Cache-Control` directives (RFC 9111 section 5.2), names lowercased.
 *
 * Directives are comma-separated, each a token with an optional value
 * that's a token or a quoted string. A directive repeated, or with a value
 * that won't parse, is the RFC's "invalid" case, and the conservative
 * reading is taken where it matters (an unparseable max-age is treated as
 * stale).
 */
final readonly class CacheControl
{
    /**
     * @param  array<string, string|true>  $directives
     */
    public function __construct(
        public array $directives = [],
    ) {}

    public static function from(MessageInterface $message): self
    {
        return self::parse($message->getHeaderLine('Cache-Control'));
    }

    public static function parse(string $header): self
    {
        $directives = [];
        preg_match_all('/([!#$%&\'*+\-.^_`|~0-9A-Za-z]+)(?:\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^,\s]*))?/', $header, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $value = $match[2] ?? null;

            $directives[strtolower($match[1])] ??= match (true) {
                $value === null || $value === '' => true,
                str_starts_with($value, '"') => stripcslashes(substr($value, 1, -1)),
                default => $value,
            };
        }

        return new self($directives);
    }

    public function has(string $directive): bool
    {
        return isset($this->directives[$directive]);
    }

    /**
     * A delta-seconds directive such as max-age: 0 when present but not a
     * valid number, null when absent.
     */
    public function seconds(string $directive): ?int
    {
        if (! isset($this->directives[$directive])) {
            return null;
        }

        $value = $this->directives[$directive];

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : 0;
    }
}
