<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Collections\Collection;
use JustSteveKing\SdkToolbox\Collections\LazyCollection;
use JustSteveKing\SdkToolbox\Errors\CollectionException;
use JustSteveKing\SdkToolbox\Errors\SdkException;

/**
 * @return Collection<int, array<string, mixed>>
 */
function results(): Collection
{
    return new Collection([
        ['position' => 1, 'title' => 'Coffee', 'source' => ['name' => 'Wikipedia'], 'rating' => 4.5],
        ['position' => 2, 'title' => 'Tea', 'source' => ['name' => 'Wikipedia'], 'rating' => 4.0],
        ['position' => 3, 'title' => 'Cocoa', 'source' => ['name' => 'BBC'], 'rating' => '3.5'],
    ]);
}

it('reads first and last, with or without a test, and falls back to a default', function (): void {
    expect(results()->first()['title'])->toBe('Coffee')
        ->and(results()->last()['title'])->toBe('Cocoa')
        ->and(results()->first(fn (array $r): bool => $r['position'] > 1)['title'])->toBe('Tea')
        ->and(results()->last(fn (array $r): bool => $r['position'] < 3)['title'])->toBe('Tea')
        ->and((new Collection)->first(default: 'none'))->toBe('none')
        ->and(results()->first(fn (): bool => false, 'none'))->toBe('none');
});

it('maps, keeping keys, and flattens', function (): void {
    expect((new Collection(['a' => 1, 'b' => 2]))->map(fn (int $n, string $k): string => $k.$n)->all())->toBe(['a' => 'a1', 'b' => 'b2'])
        ->and(results()->flatMap(fn (array $r): array => [$r['position'], $r['position'] * 10])->all())->toBe([1, 10, 2, 20, 3, 30])
        ->and(results()->mapWithKeys(fn (array $r): array => [$r['title'] => $r['position']])->all())->toBe(['Coffee' => 1, 'Tea' => 2, 'Cocoa' => 3]);
});

it('filters and rejects, keeping keys until values() renumbers them', function (): void {
    $odd = (new Collection([1, 2, 3, 4]))->filter(fn (int $n): bool => $n % 2 === 1);

    expect($odd->all())->toBe([0 => 1, 2 => 3])
        ->and($odd->values()->all())->toBe([1, 3])
        ->and((new Collection([0, 1, '', 'a', null]))->filter()->values()->all())->toBe([1, 'a'])
        ->and((new Collection([1, 2, 3]))->reject(fn (int $n): bool => $n === 2)->values()->all())->toBe([1, 3]);
});

it('compares strictly with two arguments to where(), and by operator with three', function (): void {
    expect(results()->where('position', 2)->pluck('title')->all())->toBe(['Tea'])
        ->and(results()->where('position', '2')->all())->toBe([])
        ->and(results()->where('position', '==', '2')->pluck('title')->all())->toBe(['Tea'])
        ->and(results()->where('position', '<=', 2)->pluck('title')->all())->toBe(['Coffee', 'Tea'])
        ->and(results()->where('position', '!==', 1)->pluck('title')->all())->toBe(['Tea', 'Cocoa'])
        ->and(results()->where('position', '!=', '1')->pluck('title')->all())->toBe(['Tea', 'Cocoa'])
        ->and(results()->whereIn('title', ['Tea', 'Cocoa'])->count())->toBe(2)
        ->and(results()->firstWhere('source.name', 'BBC')['title'])->toBe('Cocoa')
        ->and(results()->firstWhere('position', '>', 1)['title'])->toBe('Tea');
});

it('refuses an operator it does not know', function (): void {
    expect(fn () => results()->where('position', 'like', 1))->toThrow(CollectionException::class);
});

it('plucks fields by dotted path, keyed by another when asked', function (): void {
    expect(results()->pluck('source.name')->all())->toBe(['Wikipedia', 'Wikipedia', 'BBC'])
        ->and(results()->pluck('title', 'position')->all())->toBe([1 => 'Coffee', 2 => 'Tea', 3 => 'Cocoa'])
        ->and(results()->pluck('missing')->all())->toBe([null, null, null]);
});

it('reads public properties, ArrayAccess and arrays alike', function (): void {
    $object = new class
    {
        public string $name = 'Ada';

        public ?string $email = null;

        /** @var array<string, string> */
        public array $address = ['city' => 'London'];

        private string $secret = 'x';
    };

    $items = new Collection([$object, new ArrayObject(['name' => 'Grace'])]);

    expect($items->pluck('name')->all())->toBe(['Ada', 'Grace'])
        ->and($items->pluck('address.city')->all())->toBe(['London', null])
        ->and($items->first()->email ?? 'unset')->toBe('unset')
        ->and($items->where('email', null)->count())->toBe(2)
        ->and($items->where('name', 'Ada')->count())->toBe(1)
        ->and($items->pluck('secret')->all())->toBe([null, null]);
});

it('reads a key with a dot in it as written before reading it as a path', function (): void {
    expect((new Collection([['a.b' => 1, 'a' => ['b' => 2]]]))->pluck('a.b')->all())->toBe([1]);
});

it('treats a string as a key, never a function name', function (): void {
    expect((new Collection([['count' => 3], ['count' => 1]]))->sortBy('count')->pluck('count')->values()->all())->toBe([1, 3]);
});

it('keys and groups by a field or a closure', function (): void {
    $groups = results()->groupBy('source.name');

    expect(results()->keyBy('title')->keys()->all())->toBe(['Coffee', 'Tea', 'Cocoa'])
        ->and($groups->keys()->all())->toBe(['Wikipedia', 'BBC'])
        ->and($groups->get('Wikipedia')?->pluck('title')->all())->toBe(['Coffee', 'Tea'])
        ->and(results()->groupBy(fn (array $r): bool => $r['position'] > 1)->map(fn (Collection $g): int => $g->count())->all())->toBe([0 => 1, 1 => 2]);
});

it('refuses a key that cannot key an array', function (): void {
    expect(fn () => (new Collection([['k' => [1]]]))->keyBy('k'))->toThrow(CollectionException::class);
});

it('sorts by a field, stably, in either direction', function (): void {
    $items = new Collection([['n' => 2, 'id' => 'a'], ['n' => 1, 'id' => 'b'], ['n' => 2, 'id' => 'c']]);

    expect($items->sortBy('n')->pluck('id')->all())->toBe(['b', 'a', 'c'])
        ->and($items->sortByDesc('n')->pluck('id')->all())->toBe(['a', 'c', 'b'])
        ->and($items->sortBy('n')->keys()->all())->toBe([1, 0, 2])
        ->and($items->sortBy(fn (array $i): string => $i['id'], descending: true)->pluck('id')->all())->toBe(['c', 'b', 'a']);
});

it('takes, skips, slices, chunks and reverses', function (): void {
    $numbers = new Collection([1, 2, 3, 4, 5]);

    expect($numbers->take(2)->all())->toBe([1, 2])
        ->and($numbers->take(-2)->values()->all())->toBe([4, 5])
        ->and($numbers->skip(3)->values()->all())->toBe([4, 5])
        ->and($numbers->slice(1, 2)->values()->all())->toBe([2, 3])
        ->and($numbers->chunk(2)->map(fn (Collection $c): array => $c->values()->all())->all())->toBe([[1, 2], [3, 4], [5]])
        ->and($numbers->chunk(0)->all())->toBe([])
        ->and($numbers->reverse()->values()->all())->toBe([5, 4, 3, 2, 1]);
});

it('keeps the first of each value, or of each field', function (): void {
    expect((new Collection([1, '1', 1, 2]))->unique()->values()->all())->toBe([1, '1', 2])
        ->and(results()->unique('source.name')->pluck('title')->all())->toBe(['Coffee', 'Cocoa']);
});

it('reduces, sums, averages and finds the extremes', function (): void {
    expect(results()->sum('position'))->toBe(6)
        ->and(results()->sum('rating'))->toBe(12.0)
        ->and(results()->avg('position'))->toBe(2)
        ->and((new Collection)->avg())->toBeNull()
        ->and(results()->min('position'))->toBe(1)
        ->and(results()->max('position'))->toBe(3)
        ->and(results()->reduce(fn (int $carry, array $r): int => $carry + $r['position'], 0))->toBe(6)
        ->and(results()->implode(', ', 'title'))->toBe('Coffee, Tea, Cocoa');
});

it('answers contains and every', function (): void {
    expect((new Collection([1, 2]))->contains(2))->toBeTrue()
        ->and((new Collection([1, 2]))->contains('2'))->toBeFalse()
        ->and(results()->contains(fn (array $r): bool => $r['title'] === 'Tea'))->toBeTrue()
        ->and(results()->contains(fn (): bool => false))->toBeFalse()
        ->and((new Collection([null]))->contains(fn (mixed $v): bool => $v === null))->toBeTrue()
        ->and(results()->every(fn (array $r): bool => $r['position'] > 0))->toBeTrue()
        ->and((new Collection)->every(fn (): bool => false))->toBeTrue();
});

it('stops each() when the callback returns false', function (): void {
    $seen = [];
    (new Collection([1, 2, 3]))->each(function (int $n) use (&$seen): bool {
        $seen[] = $n;

        return $n < 2;
    });

    expect($seen)->toBe([1, 2]);
});

it('partitions and merges', function (): void {
    [$top, $rest] = results()->partition(fn (array $r): bool => $r['position'] === 1);

    expect($top->pluck('title')->all())->toBe(['Coffee'])
        ->and($rest->pluck('title')->all())->toBe(['Tea', 'Cocoa'])
        ->and((new Collection(['a' => 1, 2]))->merge(['a' => 3, 4])->all())->toBe(['a' => 3, 0 => 2, 1 => 4]);
});

it('is read-only through ArrayAccess, and both catches catch the refusal', function (): void {
    $items = new Collection(['a' => 1]);

    expect($items['a'])->toBe(1)
        ->and(isset($items['b']))->toBeFalse()
        ->and($items['b'])->toBeNull()
        ->and(function () use ($items): void {
            $items['b'] = 2;
        })->toThrow(CollectionException::class)
        ->and(function () use ($items): void {
            unset($items['a']);
        })->toThrow(CollectionException::class)
        ->and(CollectionException::immutable())->toBeInstanceOf(SdkException::class);
});

it('never changes the collection it was called on', function (): void {
    $items = new Collection([3, 1, 2]);
    $items->sortBy(fn (int $n): int => $n);
    $items->map(fn (int $n): int => $n * 2);
    $items->filter(fn (int $n): bool => $n > 1);

    expect($items->all())->toBe([3, 1, 2]);
});

it('turns nested collections into arrays for JSON', function (): void {
    $grouped = results()->groupBy('source.name')->map(fn (Collection $g): Collection => $g->pluck('title'));

    expect($grouped->toArray())->toBe(['Wikipedia' => ['Coffee', 'Tea'], 'BBC' => ['Cocoa']])
        ->and(json_encode($grouped))->toBe('{"Wikipedia":["Coffee","Tea"],"BBC":["Cocoa"]}');
});

it('counts, iterates and goes lazy', function (): void {
    expect(count(results()))->toBe(3)
        ->and(results()->isNotEmpty())->toBeTrue()
        ->and((new Collection)->isEmpty())->toBeTrue()
        ->and(iterator_to_array(new Collection(['a' => 1])))->toBe(['a' => 1])
        ->and(results()->lazy())->toBeInstanceOf(LazyCollection::class)
        ->and(results()->lazy()->pluck('title')->all())->toBe(['Coffee', 'Tea', 'Cocoa'])
        ->and(Collection::make(new ArrayIterator(['x' => 1]))->all())->toBe(['x' => 1]);
});
