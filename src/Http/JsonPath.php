<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

/**
 * Reads a value out of decoded JSON by a dotted path: `meta.next_cursor`,
 * `data.0.id`. Not JSONPath or JSON Pointer, just the envelope keys SDKs
 * actually need to reach.
 *
 * @internal
 */
final class JsonPath
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function get(array $data, ?string $path): mixed
    {
        if ($path === null || $path === '') {
            return $data;
        }

        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
