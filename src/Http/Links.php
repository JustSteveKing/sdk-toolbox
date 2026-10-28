<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use Uri\Rfc3986\Uri;

/**
 * Parses `Link` headers the way RFC 8288 Appendix B sets out.
 *
 * - Targets are resolved against the request URI (section 3.1).
 * - A link with an `anchor` is about some other resource; unless the anchor
 *   resolves to the request URI itself, the link is dropped (section 3.2).
 * - `rel` is used from its first occurrence only (section 3.3) and can name
 *   several relations, space-separated; relations compare case-insensitively.
 * - Commas and semicolons inside quoted strings don't split anything.
 *
 * Anything that won't parse is skipped, never guessed at.
 */
final class Links
{
    /**
     * @param  array<string>|string  $header  every `Link` header line
     * @return list<Link>
     */
    public static function parse(array|string $header, string $context): array
    {
        $base = Uri::parse($context);

        if ($base === null) {
            return [];
        }

        $links = [];

        foreach (is_array($header) ? $header : [$header] as $line) {
            foreach (self::split($line, ',') as $value) {
                $link = self::link(trim($value), $base);

                if ($link !== null) {
                    $links[] = $link;
                }
            }
        }

        return $links;
    }

    /**
     * @param  array<string>|string  $header
     */
    public static function find(array|string $header, string $context, string $relation): ?Link
    {
        return array_find(self::parse($header, $context), static fn (Link $link): bool => $link->is($relation));
    }

    private static function link(string $value, Uri $base): ?Link
    {
        if (preg_match('/^<([^>]*)>(.*)$/s', $value, $match) !== 1) {
            return null;
        }

        $parameters = [];

        foreach (self::split($match[2], ';') as $parameter) {
            $parameter = trim($parameter);

            if ($parameter === '') {
                continue;
            }

            [$name, $raw] = array_pad(explode('=', $parameter, 2), 2, '');
            $name = strtolower(trim($name));
            $raw = trim($raw);

            if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && strlen($raw) >= 2) {
                $raw = stripcslashes(substr($raw, 1, -1));
            }

            $parameters[$name] ??= $raw;
        }

        $target = Uri::parse($match[1], $base);

        if ($target === null || ! isset($parameters['rel'])) {
            return null;
        }

        if (isset($parameters['anchor'])) {
            $anchor = Uri::parse($parameters['anchor'], $base);

            if ($anchor === null || ! $anchor->equals($base)) {
                return null;
            }
        }

        $relations = preg_split('/\s+/', strtolower(trim($parameters['rel'])), flags: PREG_SPLIT_NO_EMPTY);

        return new Link($target->toString(), $relations === false ? [] : $relations, $parameters);
    }

    /**
     * Splits on a delimiter that isn't inside `<...>` or a quoted string.
     *
     * @return list<string>
     */
    private static function split(string $value, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $bracketed = false;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $character = $value[$i];

            if ($quoted && $character === '\\' && $i + 1 < $length) {
                $current .= $character.$value[++$i];

                continue;
            }

            match (true) {
                $character === '"' && ! $bracketed => $quoted = ! $quoted,
                $character === '<' && ! $quoted => $bracketed = true,
                $character === '>' && ! $quoted => $bracketed = false,
                default => null,
            };

            if ($character === $delimiter && ! $quoted && ! $bracketed) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return $parts;
    }
}
