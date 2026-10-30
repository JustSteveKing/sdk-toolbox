<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Collections;

use ArrayAccess;
use BackedEnum;
use Closure;
use JustSteveKing\SdkToolbox\Errors\CollectionException;
use Stringable;
use UnitEnum;

/**
 * Reads a value out of an item by key or dotted path, and compares values
 * for where(). Items are whatever an API gave back: decoded JSON arrays,
 * typed objects, or a mix.
 *
 * @internal
 */
final readonly class Values
{
    public const array OPERATORS = ['=', '==', '===', '!=', '<>', '!==', '<', '<=', '>', '>='];

    /**
     * The value at `$path` in an array, an ArrayAccess or an object's public
     * properties, such as `title` or `address.city`, or `$default` when it
     * isn't there. A key that exists as written wins over reading it as a path.
     */
    public static function get(mixed $target, string|int $path, mixed $default = null): mixed
    {
        if (self::has($target, $path)) {
            return self::read($target, $path);
        }

        if (is_int($path) || ! str_contains($path, '.')) {
            return $default;
        }

        foreach (explode('.', $path) as $segment) {
            if (! self::has($target, $segment)) {
                return $default;
            }

            $target = self::read($target, $segment);
        }

        return $target;
    }

    /**
     * A closure that reads a value from an item: the closure itself, or a key
     * or dotted path to read with get(). A string is always a key, never a
     * function name, so `'count'` and `'date'` read fields.
     *
     * @template TItem
     * @template TItemKey of array-key
     *
     * @param  (Closure(TItem, TItemKey): mixed)|string|int  $by
     * @return Closure(TItem, TItemKey): mixed
     */
    public static function reader(Closure|string|int $by): Closure
    {
        return $by instanceof Closure ? $by : static fn (mixed $item): mixed => self::get($item, $by);
    }

    /**
     * Whether `$actual` stands in `$operator` to `$expected`. The equality
     * operators work as PHP's do: `=` and `==` compare loosely, `===` strictly.
     */
    public static function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            // <=> compares as == does. Written as == here, Pint's strict
            // comparison rule would quietly turn it into ===.
            '=', '==' => ($actual <=> $expected) === 0,
            '===' => $actual === $expected,
            '!=', '<>' => ($actual <=> $expected) !== 0,
            '!==' => $actual !== $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            default => throw new CollectionException("Unknown operator {$operator}; use one of ".implode(' ', self::OPERATORS).'.'),
        };
    }

    /**
     * where()'s test: the item's `$key` stands in `$operator` to `$value`.
     *
     * @return Closure(mixed): bool
     */
    public static function where(string|int $key, string $operator, mixed $value): Closure
    {
        return static fn (mixed $item): bool => self::compare(self::get($item, $key), $operator, $value);
    }

    /**
     * where()'s second argument, when there are three: an operator.
     */
    public static function operator(mixed $operator): string
    {
        if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
            throw new CollectionException('With three arguments, the second is an operator: one of '.implode(' ', self::OPERATORS).'.');
        }

        return $operator;
    }

    /**
     * A key as an array key: ints and strings as they are, a Stringable or
     * an enum by its string value. Anything else can't key an array.
     *
     * @return array-key
     */
    public static function key(mixed $value): int|string
    {
        return match (true) {
            is_int($value), is_string($value) => $value,
            is_bool($value) => (int) $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable => (string) $value,
            $value === null => '',
            is_float($value) => (string) $value,
            default => throw new CollectionException('A '.get_debug_type($value).' cannot be used as a key.'),
        };
    }

    private static function has(mixed $target, string|int $key): bool
    {
        return match (true) {
            is_array($target) => array_key_exists($key, $target),
            $target instanceof ArrayAccess => $target->offsetExists($key),
            is_object($target) => isset($target->{$key}) || array_key_exists((string) $key, get_object_vars($target)),
            default => false,
        };
    }

    private static function read(mixed $target, string|int $key): mixed
    {
        return match (true) {
            is_array($target) => $target[$key],
            $target instanceof ArrayAccess => $target->offsetGet($key),
            is_object($target) => $target->{$key},
            default => null,
        };
    }
}
