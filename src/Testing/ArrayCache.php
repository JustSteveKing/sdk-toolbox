<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache in an array, for tests: the HTTP cache, token stores and
 * rate-limit stores all take one. TTLs run on the given clock, so a
 * FakeTime can expire entries.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: float|null}> */
    private array $items = [];

    public function __construct(
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->items[$key] ?? null;

        if ($item === null || ($item['expires'] !== null && $item['expires'] <= $this->now())) {
            return $default;
        }

        return $item['value'];
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $expires = match (true) {
            $ttl === null => null,
            $ttl instanceof DateInterval => (float) (new DateTimeImmutable('@0'))->add($ttl)->getTimestamp() + $this->now(),
            default => $ttl + $this->now(),
        };

        $this->items[$key] = ['value' => $value, 'expires' => $expires];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /**
     * @param  iterable<string>  $keys
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param  iterable<string, mixed>  $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param  iterable<string>  $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Every stored value, by key: for asserting what a store wrote.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_map(static fn (array $item): mixed => $item['value'], $this->items);
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : (float) $this->clock->now()->format('U.u');
    }
}
