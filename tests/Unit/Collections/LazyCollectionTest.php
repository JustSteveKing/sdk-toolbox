<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Collections\Collection;
use JustSteveKing\SdkToolbox\Collections\LazyCollection;

/**
 * A source that counts how many items have been pulled from it.
 *
 * @return LazyCollection<int, int>
 */
function counting(int &$pulled, int $upTo = 1_000): LazyCollection
{
    return new LazyCollection(function () use (&$pulled, $upTo): Generator {
        for ($i = 1; $i <= $upTo; $i++) {
            $pulled++;

            yield $i;
        }
    });
}

it('does nothing until it is read', function (): void {
    $pulled = 0;
    counting($pulled)->map(fn (int $n): int => $n * 2)->filter(fn (int $n): bool => $n > 10);

    expect($pulled)->toBe(0);
});

it('pulls only as many items as a chain needs', function (): void {
    $pulled = 0;
    $items = counting($pulled)->filter(fn (int $n): bool => $n % 3 === 0)->take(2)->values()->all();

    expect($items)->toBe([3, 6])->and($pulled)->toBe(6);
});

it('stops at the first match', function (): void {
    $pulled = 0;

    expect(counting($pulled)->first(fn (int $n): bool => $n > 4))->toBe(5)
        ->and($pulled)->toBe(5);

    $pulled = 0;

    expect(counting($pulled)->contains(3))->toBeTrue()->and($pulled)->toBe(3);

    $pulled = 0;

    expect(counting($pulled)->every(fn (int $n): bool => $n < 4))->toBeFalse()->and($pulled)->toBe(4);

    $pulled = 0;

    expect(counting($pulled)->isEmpty())->toBeFalse()->and($pulled)->toBe(1);
});

it('pulls nothing for take(0)', function (): void {
    $pulled = 0;

    expect(counting($pulled)->take(0)->all())->toBe([])->and($pulled)->toBe(0);
});

it('reads the source again on every read', function (): void {
    $pulled = 0;
    $items = counting($pulled, 3);

    $items->all();
    $items->all();

    expect($pulled)->toBe(6);
});

it('has the collection methods, lazily', function (): void {
    $rows = new LazyCollection(fn (): array => [
        ['id' => 'a', 'n' => 1, 'tags' => ['x']],
        ['id' => 'b', 'n' => 2, 'tags' => ['y', 'z']],
        ['id' => 'c', 'n' => 2, 'tags' => []],
    ]);

    expect($rows->where('n', 2)->pluck('id')->all())->toBe(['b', 'c'])
        ->and($rows->where('n', '2')->count())->toBe(0)
        ->and($rows->firstWhere('n', '2'))->toBeNull()
        ->and($rows->where('n', '<', 2)->pluck('id')->all())->toBe(['a'])
        ->and($rows->whereIn('id', ['a', 'c'])->count())->toBe(2)
        ->and($rows->firstWhere('n', 2)['id'] ?? null)->toBe('b')
        ->and($rows->pluck('n', 'id')->all())->toBe(['a' => 1, 'b' => 2, 'c' => 2])
        ->and($rows->flatMap(fn (array $r): array => $r['tags'])->all())->toBe(['x', 'y', 'z'])
        ->and($rows->reject(fn (array $r): bool => $r['n'] === 2)->count())->toBe(1)
        ->and($rows->unique('n')->pluck('id')->all())->toBe(['a', 'b'])
        ->and($rows->skip(1)->pluck('id')->all())->toBe(['b', 'c'])
        ->and($rows->takeWhile(fn (array $r): bool => $r['n'] === 1)->count())->toBe(1)
        ->and($rows->keys()->all())->toBe([0, 1, 2])
        ->and($rows->sum('n'))->toBe(5)
        ->and($rows->reduce(fn (string $carry, array $r): string => $carry.$r['id'], ''))->toBe('abc')
        ->and($rows->chunk(2)->map(fn (Collection $c): array => $c->pluck('id')->all())->all())->toBe([['a', 'b'], ['c']])
        ->and($rows->collect())->toBeInstanceOf(Collection::class)
        ->and(json_encode($rows->pluck('id')))->toBe('["a","b","c"]')
        ->and(count($rows))->toBe(3);
});

it('keeps keys through filter() until values() renumbers them', function (): void {
    $odd = (new LazyCollection(fn (): array => [1, 2, 3]))->filter(fn (int $n): bool => $n !== 2);

    expect($odd->all())->toBe([0 => 1, 2 => 3])->and($odd->values()->all())->toBe([1, 3]);
});

it('stops each() when the callback returns false', function (): void {
    $pulled = 0;
    counting($pulled)->each(fn (int $n): bool => $n < 3);

    expect($pulled)->toBe(3);
});
