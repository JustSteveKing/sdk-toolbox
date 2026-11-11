<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Testing\ArrayCache;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use JustSteveKing\SdkToolbox\Webhooks\SeenDeliveries;

it('handles a delivery the first time and refuses it after', function (): void {
    $seen = new SeenDeliveries(new ArrayCache(new FakeTime));

    expect($seen->firstTime('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W'))->toBeTrue()
        ->and($seen->firstTime('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W'))->toBeFalse()
        ->and($seen->firstTime('msg_other'))->toBeTrue();
});

it('forgets an ID once its time is up', function (): void {
    $time = new FakeTime;
    $seen = new SeenDeliveries(new ArrayCache($time), ttl: 600);

    $seen->firstTime('msg_1');
    $time->advance(599);
    expect($seen->firstTime('msg_1'))->toBeFalse();

    $time->advance(2);
    expect($seen->firstTime('msg_1'))->toBeTrue();
});

it('lets a failed delivery be handled again once forgotten', function (): void {
    $seen = new SeenDeliveries(new ArrayCache(new FakeTime));

    $seen->firstTime('msg_1');
    $seen->forget('msg_1');

    expect($seen->firstTime('msg_1'))->toBeTrue();
});

/**
 * A cache that refuses the characters PSR-16 reserves in keys, as real
 * implementations do; ArrayCache doesn't check.
 */
function strictCache(): Psr\SimpleCache\CacheInterface
{
    return new class(new ArrayCache(new FakeTime)) implements Psr\SimpleCache\CacheInterface
    {
        public function __construct(private ArrayCache $inner) {}

        private function check(string $key): string
        {
            if (strpbrk($key, '{}()/\\@:') !== false) {
                throw new class("Reserved character in [{$key}]") extends InvalidArgumentException implements Psr\SimpleCache\InvalidArgumentException {};
            }

            return $key;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->inner->get($this->check($key), $default);
        }

        public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
        {
            return $this->inner->set($this->check($key), $value, $ttl);
        }

        public function delete(string $key): bool
        {
            return $this->inner->delete($this->check($key));
        }

        public function clear(): bool
        {
            return $this->inner->clear();
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return $this->inner->getMultiple($keys, $default);
        }

        public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
        {
            return $this->inner->setMultiple($values, $ttl);
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return $this->inner->deleteMultiple($keys);
        }

        public function has(string $key): bool
        {
            return $this->inner->has($this->check($key));
        }
    };
}

it('takes IDs PSR-16 would refuse as keys', function (): void {
    $seen = new SeenDeliveries(strictCache());

    expect($seen->firstTime('evt:{a}/b@c'))->toBeTrue()
        ->and($seen->firstTime('evt:{a}/b@c'))->toBeFalse();
});

it('shares what it has seen through the cache', function (): void {
    $cache = new ArrayCache(new FakeTime);

    (new SeenDeliveries($cache))->firstTime('msg_1');

    expect((new SeenDeliveries($cache))->firstTime('msg_1'))->toBeFalse();
});

it('refuses a ttl that keeps nothing', function (): void {
    expect(fn () => new SeenDeliveries(new ArrayCache, ttl: 0))->toThrow(InvalidRequestException::class);
});
