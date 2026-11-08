<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use BackedEnum;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use Stringable;

/**
 * Encodes query strings and form bodies the way APIs expect them.
 *
 * PHP's own http_build_query() sends `true` as `1` and drops `false`
 * entirely, which most APIs read as something else. Here `null` is left
 * out, booleans are `true` and `false`, backed enums send their value, and
 * nested arrays use PHP's bracket form. Encoding is RFC 3986: a space is
 * `%20`, and brackets are `%5B` and `%5D`.
 *
 * Lists are indexed, `ids[0]=1`, unless a ListFormat says otherwise:
 * `ids[]=1` or `ids=1`. That applies at any depth, so a list inside a
 * keyed array is `filter[status][]=open`. A list item that is itself an
 * array keeps its index, because `items[][id]=1&items[][qty]=2` can't
 * say whether that's one item or two.
 */
final class QueryString
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function build(array $values, ListFormat $lists = ListFormat::Indexed): string
    {
        if ($lists === ListFormat::Indexed) {
            return http_build_query(self::normalise($values), '', '&', PHP_QUERY_RFC3986);
        }

        return implode('&', self::pairs($values, '', $lists));
    }

    /**
     * `name=value` pairs, encoded as http_build_query() encodes them,
     * except that a list's items are named as `$lists` says. Whether an
     * array is a list is decided before null items are left out.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function pairs(array $values, string $prefix, ListFormat $lists): array
    {
        $list = array_is_list($values);
        $pairs = [];

        foreach ($values as $key => $value) {
            $value = self::value($key, $value);

            if ($value === null) {
                continue;
            }

            $name = match (true) {
                $prefix === '' => rawurlencode((string) $key),
                $list && ! is_array($value) && $lists === ListFormat::Repeated => $prefix,
                $list && ! is_array($value) => "{$prefix}%5B%5D",
                default => $prefix.'%5B'.rawurlencode((string) $key).'%5D',
            };

            if (is_array($value)) {
                array_push($pairs, ...self::pairs($value, $name, $lists));
            } else {
                $pairs[] = $name.'='.rawurlencode($value);
            }
        }

        return $pairs;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function normalise(array $values): array
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            $value = self::value($key, $value);

            if ($value !== null) {
                $normalised[$key] = is_array($value) ? self::normalise($value) : $value;
            }
        }

        return $normalised;
    }

    /**
     * A value as it's sent: a string, an array still to encode, or null to
     * leave out.
     *
     * @return string|array<array-key, mixed>|null
     */
    private static function value(int|string $key, mixed $value): string|array|null
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof BackedEnum => (string) $value->value,
            is_array($value) => $value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => throw new InvalidRequestException(
                sprintf('Query and form values must be scalars, enums, Stringable or arrays; [%s] is %s.', $key, get_debug_type($value)),
            ),
        };
    }
}
