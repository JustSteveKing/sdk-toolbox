<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Collections;

use Closure;
use Countable;
use Generator;
use IteratorAggregate;
use JsonSerializable;

/**
 * Collection methods over items that arrive one at a time, such as every
 * item across an API's pages. Nothing runs until something reads it, and
 * a chain only pulls the items it needs:
 *
 *     $acme->paginate(new ListMembers, new LinkPagination)
 *         ->filter(fn (Member $member) => $member->active)
 *         ->take(10)
 *         ->all();
 *
 * fetches pages until ten active members have turned up, then stops.
 *
 * Each read starts again from the source. For a Paginator that means
 * fetching the pages again, so to read the same items twice, collect()
 * them once and use the Collection.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @implements IteratorAggregate<TKey, TValue>
 */
readonly class LazyCollection implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  Closure(): iterable<TKey, TValue>  $source  called afresh for every read
     */
    public function __construct(private Closure $source) {}

    /**
     * @return Generator<TKey, TValue>
     */
    public function getIterator(): Generator
    {
        yield from ($this->source)();
    }

    /**
     * Every item in memory, as a Collection.
     *
     * @return Collection<TKey, TValue>
     */
    public function collect(): Collection
    {
        return new Collection($this->all());
    }

    /**
     * Every item in memory, keys and all. Fine for a few pages; iterate
     * instead for more.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        $items = [];

        foreach ($this as $key => $item) {
            $items[$key] = $item;
        }

        return $items;
    }

    /**
     * @return array<TKey, mixed>
     */
    public function toArray(): array
    {
        return $this->collect()->toArray();
    }

    /**
     * @return array<TKey, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The first item, or the first that passes `$callback`, or `$default`.
     * Reads only as far as it has to.
     *
     * @template TDefault
     *
     * @param  (Closure(TValue, TKey): bool)|null  $callback
     * @param  TDefault  $default
     * @return TValue|TDefault
     */
    public function first(?Closure $callback = null, mixed $default = null): mixed
    {
        foreach ($this as $key => $item) {
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
        return $this->first(func_num_args() === 2
            ? Values::where($key, '===', $operator)
            : Values::where($key, Values::operator($operator), $value));
    }

    /**
     * @template TMapValue
     *
     * @param  Closure(TValue, TKey): TMapValue  $callback
     * @return self<TKey, TMapValue>
     */
    public function map(Closure $callback): self
    {
        return new self(function () use ($callback): Generator {
            foreach ($this as $key => $item) {
                yield $key => $callback($item, $key);
            }
        });
    }

    /**
     * @template TFlatValue
     *
     * @param  Closure(TValue, TKey): iterable<array-key, TFlatValue>  $callback
     * @return self<int, TFlatValue>
     */
    public function flatMap(Closure $callback): self
    {
        return new self(function () use ($callback): Generator {
            $index = 0;

            foreach ($this as $key => $item) {
                foreach ($callback($item, $key) as $value) {
                    yield $index++ => $value;
                }
            }
        });
    }

    /**
     * @param  (Closure(TValue, TKey): bool)|null  $callback
     * @return self<TKey, TValue>
     */
    public function filter(?Closure $callback = null): self
    {
        return new self(function () use ($callback): Generator {
            foreach ($this as $key => $item) {
                if ($callback === null ? (bool) $item : $callback($item, $key)) {
                    yield $key => $item;
                }
            }
        });
    }

    /**
     * @param  Closure(TValue, TKey): bool  $callback
     * @return self<TKey, TValue>
     */
    public function reject(Closure $callback): self
    {
        return $this->filter(static fn (mixed $item, int|string $key): bool => ! $callback($item, $key));
    }

    /**
     * The items whose `$key` matches, compared as Collection::where() does.
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
     * @param  iterable<mixed>  $values
     * @return self<TKey, TValue>
     */
    public function whereIn(string|int $key, iterable $values): self
    {
        $values = is_array($values) ? $values : iterator_to_array($values, false);

        return $this->filter(static fn (mixed $item): bool => in_array(Values::get($item, $key), $values, true));
    }

    /**
     * @return self<array-key, mixed>
     */
    public function pluck(string|int $value, string|int|null $keyBy = null): self
    {
        return new self(function () use ($value, $keyBy): Generator {
            $index = 0;

            foreach ($this as $item) {
                yield ($keyBy === null ? $index++ : Values::key(Values::get($item, $keyBy))) => Values::get($item, $value);
            }
        });
    }

    /**
     * The first `$count` items. Stops reading the source as soon as it has
     * them, so a paginator fetches no page it doesn't need.
     *
     * @return self<TKey, TValue>
     */
    public function take(int $count): self
    {
        return new self(function () use ($count): Generator {
            if ($count < 1) {
                return;
            }

            $taken = 0;

            foreach ($this as $key => $item) {
                yield $key => $item;

                if (++$taken >= $count) {
                    return;
                }
            }
        });
    }

    /**
     * The items for as long as `$callback` passes.
     *
     * @param  Closure(TValue, TKey): bool  $callback
     * @return self<TKey, TValue>
     */
    public function takeWhile(Closure $callback): self
    {
        return new self(function () use ($callback): Generator {
            foreach ($this as $key => $item) {
                if (! $callback($item, $key)) {
                    return;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * @return self<TKey, TValue>
     */
    public function skip(int $count): self
    {
        return new self(function () use ($count): Generator {
            $skipped = 0;

            foreach ($this as $key => $item) {
                if ($skipped++ < $count) {
                    continue;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * The items in collections of `$size`, each held in memory only while
     * it's read.
     *
     * @return self<int, Collection<TKey, TValue>>
     */
    public function chunk(int $size): self
    {
        return new self(function () use ($size): Generator {
            if ($size < 1) {
                return;
            }

            $chunk = [];
            $index = 0;

            foreach ($this as $key => $item) {
                $chunk[$key] = $item;

                if (count($chunk) === $size) {
                    yield $index++ => new Collection($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                yield $index => new Collection($chunk);
            }
        });
    }

    /**
     * The items once each, by value or by a field, keeping the first. It
     * remembers every value it has seen, so memory grows with the distinct ones.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     * @return self<TKey, TValue>
     */
    public function unique(Closure|string|int|null $by = null): self
    {
        $read = $by === null ? static fn (mixed $item): mixed => $item : Values::reader($by);

        return new self(function () use ($read): Generator {
            $seen = [];

            foreach ($this as $key => $item) {
                $value = $read($item, $key);

                if (! in_array($value, $seen, true)) {
                    $seen[] = $value;

                    yield $key => $item;
                }
            }
        });
    }

    /**
     * @return self<int, TValue>
     */
    public function values(): self
    {
        return new self(function (): Generator {
            foreach ($this as $item) {
                yield $item;
            }
        });
    }

    /**
     * @return self<int, TKey>
     */
    public function keys(): self
    {
        return new self(function (): Generator {
            foreach ($this as $key => $item) {
                yield $key;
            }
        });
    }

    /**
     * Calls `$callback` with each item, reading them all. Returning false
     * stops early.
     *
     * @param  Closure(TValue, TKey): mixed  $callback
     */
    public function each(Closure $callback): void
    {
        foreach ($this as $key => $item) {
            if ($callback($item, $key) === false) {
                return;
            }
        }
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

        foreach ($this as $key => $item) {
            $carry = $callback($carry, $item, $key);
        }

        return $carry;
    }

    /**
     * Whether an item passes `$value` when it's a closure, or is `$value`,
     * compared strictly. Stops at the first match.
     */
    public function contains(mixed $value): bool
    {
        foreach ($this as $key => $item) {
            if ($value instanceof Closure ? $value($item, $key) : $item === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every item passes `$callback`. Stops at the first that fails.
     *
     * @param  Closure(TValue, TKey): bool  $callback
     */
    public function every(Closure $callback): bool
    {
        foreach ($this as $key => $item) {
            if (! $callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many items there are, reading every one: for a Paginator, every
     * page. `count($paginator)` does the same.
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this as $item) {
            $count++;
        }

        return $count;
    }

    /**
     * Whether there are no items, reading at most one.
     */
    public function isEmpty(): bool
    {
        foreach ($this as $item) {
            return false;
        }

        return true;
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * The total of the items, or of a field, reading every one.
     *
     * @param  (Closure(TValue, TKey): mixed)|string|int|null  $by
     */
    public function sum(Closure|string|int|null $by = null): int|float
    {
        $read = $by === null ? static fn (mixed $item): mixed => $item : Values::reader($by);
        $total = 0;

        foreach ($this as $key => $item) {
            $value = $read($item, $key);
            $total += is_int($value) || is_float($value) ? $value : (is_numeric($value) ? +$value : 0);
        }

        return $total;
    }
}
