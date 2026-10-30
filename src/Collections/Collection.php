<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Collections;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use JustSteveKing\SdkToolbox\Errors\CollectionException;
use Stringable;
use Traversable;

/**
 * Items an API gave back, in memory, with the methods you'd otherwise write
 * as loops: first(), map(), filter(), pluck(), groupBy() and the rest. The
 * names are the ones Laravel's collections use, so they read the same in a
 * Laravel app, a Symfony app or plain PHP, with nothing to install.
 *
 *     $response->collect('data')
 *         ->where('status', 'active')
 *         ->sortByDesc('created_at')
 *         ->pluck('name', 'id');
 *
 * Immutable: every method returns a new collection and the original is left
 * as it was. Items are whatever the API gave back, arrays or typed objects;
 * a string key such as `'address.city'` reads into either. Methods that
 * filter or sort keep the keys, as PHP's array functions do; values() renumbers
 * them.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @implements IteratorAggregate<TKey, TValue>
 * @implements ArrayAccess<TKey, TValue>
 */
final readonly class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<TKey, TValue> */
    private array $items;

    /**
     * @param  iterable<TKey, TValue>  $items
     */
    public function __construct(iterable $items = [])
    {
        $this->items = is_array($items) ? $items : iterator_to_array($items);
    }

    /**
     * @template TMakeKey of array-key
     * @template TMakeValue
     *
     * @param  iterable<TMakeKey, TMakeValue>  $items
     * @return self<TMakeKey, TMakeValue>
     */
    public static function make(iterable $items = []): self
    {
        return new self($items);
    }

    /**
     * Every item, keys and all.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * The items as plain arrays, with any collection inside turned into an
     * array too.
     *
     * @return array<TKey, mixed>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (mixed $item): mixed => $item instanceof self || $item instanceof LazyCollection ? $item->toArray() : $item,
            $this->items,
        );
    }

    /**
     * A lazy collection over these items, for a chain that should stop early.
     *
     * @return LazyCollection<TKey, TValue>
     */
    public function lazy(): LazyCollection
    {
        return new LazyCollection(fn (): array => $this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * @param  TKey  $key
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * The item at `$key`, or `$default`.
     *
     * @template TDefault
     *
     * @param  TKey  $key
     * @param  TDefault  $default
     * @return TValue|TDefault
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    /**
     * The first item, or the first that passes `$callback`, or `$default`.
     *
     * @template TDefault
     *
     * @param  (Closure(TValue, TKey): bool)|null  $callback
     * @param  TDefault  $default
     * @return TValue|TDefault
     */
    public function first(?Closure $callback = null, mixed $default = null): mixed
    {
        foreach ($this->items as $key => $item) {
            if ($callback === null || $callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * The last item, or the last that passes `$callback`, or `$default`.
     *
     * @template TDefault
     *
     * @param  (Closure(TValue, TKey): bool)|null  $callback
     * @param  TDefault  $default
     * @return TValue|TDefault
     */
    public function last(?Closure $callback = null, mixed $default = null): mixed
    {
        foreach (array_reverse($this->items, true) as $key => $item) {
            if ($callback === null || $callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * The first item whose `$key` matches, as where() reads it.
     *
     * @return TValue|null
     */
    public function firstWhere(string|int $key, mixed $operator = null, mixed $value = null): mixed
    {
        $test = func_num_args() === 2 ? Values::where($key, '===', $operator) : Values::where($key, Values::operator($operator), $value);

        return $this->first($test);
    }

    /**
     * @template TMapValue
     *
     * @param  Closure(TValue, TKey): TMapValue  $callback
     * @return self<TKey, TMapValue>
     */
    public function map(Closure $callback): self
    {
        $keys = array_keys($this->items);

        return new self(array_combine($keys, array_map($callback, $this->items, $keys)));
    }

    /**
     * Each item mapped to an iterable, and the results joined into one list.
     *
     * @template TFlatValue
     *
     * @param  Closure(TValue, TKey): iterable<array-key, TFlatValue>  $callback
     * @return self<int, TFlatValue>
     */
    public function flatMap(Closure $callback): self
    {
        $items = [];

        foreach ($this->items as $key => $item) {
            foreach ($callback($item, $key) as $value) {
                $items[] = $value;
            }
        }

        return new self($items);
    }

    /**
     * Each item as one key and value: `fn ($user) => [$user['id'] => $user['name']]`.
     *
     * @template TMapKey of array-key
     * @template TMapValue
     *
     * @param  Closure(TValue, TKey): array<TMapKey, TMapValue>  $callback
     * @return self<TMapKey, TMapValue>
     */
    public function mapWithKeys(Closure $callback): self
    {
        $items = [];

        foreach ($this->items as $key => $item) {
            foreach ($callback($item, $key) as $newKey => $value) {
                $items[$newKey] = $value;
            }
        }

        return new self($items);
    }

    /**
     * The items that pass `$callback`, or that are truthy without one.
     *
     * @param  (Closure(TValue, TKey): bool)|null  $callback
     * @return self<TKey, TValue>
     */
    public function filter(?Closure $callback = null): self
    {
        return new self($callback === null
            ? array_filter($this->items)
            : array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * The items that fail `$callback`.
     *
     * @param  Closure(TValue, TKey): bool  $callback
     * @return self<TKey, TValue>
     */
    public function reject(Closure $callback): self
    {
        return $this->filter(static fn (mixed $item, int|string $key): bool => ! $callback($item, $key));
    }

    /**
     * The items whose `$key` matches. With two arguments it compares
     * strictly, `where('status', 'active')`; with three it takes an operator,
     * `where('position', '<=', 3)`, one of `= == === != <> !== < <= > >=`.
     *
     * @return self<TKey, TValue>
     */
    public function where(string|int $key, mixed $operator = null, mixed $value = null): self
    {
        return $this->filter(func_num_args() === 2
            ? Values::where($key, '===', $operator)
            : Values::where($key, Values::operator($operator), $value));
    }

    /**
     * The items whose `$key` is one of `$values`, compared strictly.
     *
     * @param  iterable<mixed>  $values
     * @return self<TKey, TValue>
     */
    public function whereIn(string|int $key, iterable $values): self
    {
        $values = is_array($values) ? $values : iterator_to_array($values, false);

        return $this->filter(static fn (mixed $item): bool => in_array(Values::get($item, $key), $values, true));
    }

    /**
     * One field from every item, keyed by another when `$keyBy` is given:
     * `pluck('title')`, `pluck('name', 'id')`.
     *
     * @return self<array-key, mixed>
     */
    public function pluck(string|int $value, string|int|null $keyBy = null): self
    {
        $items = [];

        foreach ($this->items as $item) {
            if ($keyBy === null) {
                $items[] = Values::get($item, $value);
            } else {
                $items[Values::key(Values::get($item, $keyBy))] = Values::get($item, $value);
            }
        }

        return new self($items);
    }

    /**
     * The items keyed by a field, or by what `$by` returns. A later item
     * with the same key replaces an earlier one.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int  $by
     * @return self<array-key, TValue>
     */
    public function keyBy(Closure|string|int $by): self
    {
        $read = Values::reader($by);
        $items = [];

        foreach ($this->items as $key => $item) {
            $items[Values::key($read($item, $key))] = $item;
        }

        return new self($items);
    }

    /**
     * The items in groups, by a field or by what `$by` returns.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int  $by
     * @return self<array-key, self<int, TValue>>
     */
    public function groupBy(Closure|string|int $by): self
    {
        $read = Values::reader($by);
        $groups = [];

        foreach ($this->items as $key => $item) {
            $groups[Values::key($read($item, $key))][] = $item;
        }

        return new self(array_map(static fn (array $group): self => new self($group), $groups));
    }

    /**
     * Two collections: the items that pass `$callback`, and the rest.
     *
     * @param  Closure(TValue, TKey): bool  $callback
     * @return array{self<TKey, TValue>, self<TKey, TValue>}
     */
    public function partition(Closure $callback): array
    {
        return [$this->filter($callback), $this->reject($callback)];
    }

    /**
     * Sorted by a field or by what `$by` returns, keeping keys. Items that
     * compare equal keep their order.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int  $by
     * @return self<TKey, TValue>
     */
    public function sortBy(Closure|string|int $by, bool $descending = false): self
    {
        $read = Values::reader($by);
        $values = [];

        foreach ($this->items as $key => $item) {
            $values[$key] = $read($item, $key);
        }

        // usort() is stable from PHP 8.0, so equal items keep their order.
        $keys = array_keys($this->items);
        usort($keys, static fn (int|string $a, int|string $b): int => $descending ? $values[$b] <=> $values[$a] : $values[$a] <=> $values[$b]);

        $items = [];

        foreach ($keys as $key) {
            $items[$key] = $this->items[$key];
        }

        return new self($items);
    }

    /**
     * @param  (Closure(TValue, TKey): mixed)|string|int  $by
     * @return self<TKey, TValue>
     */
    public function sortByDesc(Closure|string|int $by): self
    {
        return $this->sortBy($by, descending: true);
    }

    /**
     * @return self<TKey, TValue>
     */
    public function reverse(): self
    {
        return new self(array_reverse($this->items, true));
    }

    /**
     * The first `$count` items, or the last `-$count` when it's negative.
     *
     * @return self<TKey, TValue>
     */
    public function take(int $count): self
    {
        return $count < 0 ? $this->slice($count) : $this->slice(0, $count);
    }

    /**
     * Every item after the first `$count`.
     *
     * @return self<TKey, TValue>
     */
    public function skip(int $count): self
    {
        return $this->slice(max(0, $count));
    }

    /**
     * @return self<TKey, TValue>
     */
    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length, true));
    }

    /**
     * The items in collections of `$size`, the last one possibly shorter.
     *
     * @return self<int, self<TKey, TValue>>
     */
    public function chunk(int $size): self
    {
        if ($size < 1) {
            return new self;
        }

        return new self(array_map(
            static fn (array $chunk): self => new self($chunk),
            array_chunk($this->items, $size, true),
        ));
    }

    /**
     * The items once each, by value or by a field, keeping the first.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     * @return self<TKey, TValue>
     */
    public function unique(Closure|string|int|null $by = null): self
    {
        $read = $by === null ? static fn (mixed $item): mixed => $item : Values::reader($by);
        $seen = [];
        $items = [];

        foreach ($this->items as $key => $item) {
            $value = $read($item, $key);

            if (! in_array($value, $seen, true)) {
                $seen[] = $value;
                $items[$key] = $item;
            }
        }

        return new self($items);
    }

    /**
     * These items followed by `$items`. String keys in `$items` replace the
     * same keys here, and integer keys are renumbered, as array_merge() does.
     *
     * @template TMergeValue
     *
     * @param  iterable<array-key, TMergeValue>  $items
     * @return self<array-key, TValue|TMergeValue>
     */
    public function merge(iterable $items): self
    {
        return new self(array_merge($this->items, is_array($items) ? $items : iterator_to_array($items)));
    }

    /**
     * @return self<int, TValue>
     */
    public function values(): self
    {
        return new self(array_values($this->items));
    }

    /**
     * @return self<int, TKey>
     */
    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    /**
     * Calls `$callback` with each item. Returning false stops early.
     *
     * @param  Closure(TValue, TKey): mixed  $callback
     * @return self<TKey, TValue>
     */
    public function each(Closure $callback): self
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * @template TCarry
     *
     * @param  Closure(TCarry, TValue, TKey): TCarry  $callback
     * @param  TCarry  $initial
     * @return TCarry
     */
    public function reduce(Closure $callback, mixed $initial = null): mixed
    {
        $carry = $initial;

        foreach ($this->items as $key => $item) {
            $carry = $callback($carry, $item, $key);
        }

        return $carry;
    }

    /**
     * Whether an item passes `$value` when it's a closure, or is `$value`,
     * compared strictly.
     */
    public function contains(mixed $value): bool
    {
        if ($value instanceof Closure) {
            return $this->first($value, $this) !== $this;
        }

        return in_array($value, $this->items, true);
    }

    /**
     * Whether every item passes `$callback`. True when there are none.
     *
     * @param  Closure(TValue, TKey): bool  $callback
     */
    public function every(Closure $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if (! $callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The total of the items, or of a field.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     */
    public function sum(Closure|string|int|null $by = null): int|float
    {
        return array_sum($this->numbers($by));
    }

    /**
     * The mean of the items, or of a field, or null when there are none.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     */
    public function avg(Closure|string|int|null $by = null): int|float|null
    {
        $numbers = $this->numbers($by);

        return $numbers === [] ? null : array_sum($numbers) / count($numbers);
    }

    /**
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     */
    public function min(Closure|string|int|null $by = null): mixed
    {
        $values = $this->read($by);

        return $values === [] ? null : min($values);
    }

    /**
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     */
    public function max(Closure|string|int|null $by = null): mixed
    {
        $values = $this->read($by);

        return $values === [] ? null : max($values);
    }

    /**
     * The items, or a field of each, joined into one string.
     */
    public function implode(string $glue, string|int|null $field = null): string
    {
        return implode($glue, array_map(
            static fn (mixed $value): string => is_scalar($value) || $value instanceof Stringable ? (string) $value : '',
            $this->read($field),
        ));
    }

    /**
     * @return Traversable<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return array<TKey, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  TKey  $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    /**
     * @param  TKey  $offset
     * @return TValue|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw CollectionException::immutable();
    }

    public function offsetUnset(mixed $offset): never
    {
        throw CollectionException::immutable();
    }

    /**
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     * @return list<mixed>
     */
    private function read(Closure|string|int|null $by): array
    {
        if ($by === null) {
            return array_values($this->items);
        }

        $read = Values::reader($by);
        $values = [];

        foreach ($this->items as $key => $item) {
            $values[] = $read($item, $key);
        }

        return $values;
    }

    /**
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     * @return list<int|float>
     */
    private function numbers(Closure|string|int|null $by): array
    {
        return array_map(
            static fn (mixed $value): int|float => is_int($value) || is_float($value) ? $value : (is_numeric($value) ? +$value : 0),
            $this->read($by),
        );
    }
}
