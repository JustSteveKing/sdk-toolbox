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
 * `%20`.
 */
final class QueryString
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public static function build(array $values): string
    {
        return http_build_query(self::normalise($values), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function normalise(array $values): array
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            $value = match (true) {
                $value === null => null,
                is_bool($value) => $value ? 'true' : 'false',
                $value instanceof BackedEnum => (string) $value->value,
                is_array($value) => self::normalise($value),
                is_scalar($value), $value instanceof Stringable => (string) $value,
                default => throw new InvalidRequestException(
                    sprintf('Query and form values must be scalars, enums, Stringable or arrays; [%s] is %s.', $key, get_debug_type($value)),
                ),
            };

            if ($value !== null) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }
}
