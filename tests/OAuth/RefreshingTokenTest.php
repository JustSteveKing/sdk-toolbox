<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\OAuth\AccessToken;
use JustSteveKing\SdkToolbox\Auth\OAuth\InMemoryTokenStore;
use JustSteveKing\SdkToolbox\Auth\OAuth\Psr16TokenStore;
use JustSteveKing\SdkToolbox\Auth\OAuth\RefreshingToken;
use JustSteveKing\SdkToolbox\Auth\OAuth\RefreshLock;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\OAuthException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use Psr\SimpleCache\CacheInterface;

function userApi(FakeClient $http, RefreshingToken $auth, FakeTime $time): Connector
{
    return new Connector('https://api.acme.test', auth: $auth, http: $http, clock: $time, sleeper: $time);
}

it('refreshes an expired token and keeps the new refresh token (RFC 6749 6)', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('user:1', new AccessToken('old', $time->now()->modify('-1 minute'), 'refresh-1', ['read']));
    $auth = new FakeClient(token('new', refresh: 'refresh-2'));

    userApi(new FakeClient(FakeResponse::empty(200)), new RefreshingToken(oauthClient($auth, $time), 'user:1', $store), $time)->response(ping());

    expect(form($auth->last()))->toBe(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-1'])
        ->and($store->get('user:1')?->token)->toBe('new')
        ->and($store->get('user:1')?->refreshToken)->toBe('refresh-2')
        ->and($store->get('user:1')?->scopes)->toBe(['read']);
});

it('keeps the old refresh token when the server issues no new one', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('user:1', new AccessToken('old', $time->now()->modify('-1 minute'), 'refresh-1'));

    userApi(new FakeClient(FakeResponse::empty(200)), new RefreshingToken(oauthClient(new FakeClient(token('new')), $time), 'user:1', $store), $time)->response(ping());

    expect($store->get('user:1')?->refreshToken)->toBe('refresh-1');
});

it('says the user has to authorise again when there is nothing to refresh with', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('user:1', new AccessToken('old', $time->now()->modify('-1 minute')));

    expect(fn () => userApi(new FakeClient, new RefreshingToken(oauthClient(new FakeClient, $time), 'user:1', $store), $time)->response(ping()))
        ->toThrow(OAuthException::class, 'no_refresh_token');
});

it('uses a token another process refreshed while it waited for the lock', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('user:1', new AccessToken('stale', $time->now()->modify('-1 minute'), 'refresh-1'));

    // The lock is held by another process, which refreshes while we wait.
    $lock = new readonly class($store, $time) implements RefreshLock
    {
        public function __construct(private InMemoryTokenStore $store, private FakeTime $time) {}

        public function synchronized(string $key, Closure $callback): mixed
        {
            $this->store->put($key, new AccessToken('refreshed-elsewhere', $this->time->now()->modify('+1 hour'), 'refresh-2'));

            return $callback();
        }
    };
    $auth = new FakeClient;
    $http = new FakeClient(FakeResponse::empty(200));

    userApi($http, new RefreshingToken(oauthClient($auth, $time), 'user:1', $store, $lock), $time)->response(ping());

    $auth->assertNothingSent();
    expect($http->last()?->getHeaderLine('Authorization'))->toBe('Bearer refreshed-elsewhere');
});

it('never hands out a token the API rejected, even if it has not expired', function (): void {
    $time = new FakeTime;
    $store = new InMemoryTokenStore;
    $store->put('user:1', new AccessToken('revoked', $time->now()->modify('+1 hour'), 'refresh-1'));
    $http = new FakeClient(FakeResponse::empty(401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']), FakeResponse::empty(200));

    userApi($http, new RefreshingToken(oauthClient(new FakeClient(token('new', refresh: 'refresh-2')), $time), 'user:1', $store), $time)->response(ping());

    expect($http->last()?->getHeaderLine('Authorization'))->toBe('Bearer new');
});

it('stores tokens in a PSR-16 cache as plain arrays, never serialized objects', function (): void {
    $cache = new class implements CacheInterface
    {
        /** @var array<string, mixed> */
        public array $items = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
        {
            $this->items[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function clear(): bool
        {
            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return isset($this->items[$key]);
        }
    };
    $store = new Psr16TokenStore($cache);
    $token = new AccessToken('t', new DateTimeImmutable('@1800000000'), 'r', ['a', 'b']);

    $store->put('user:1', $token);

    expect(array_values($cache->items))->toBe([['token' => 't', 'expires_at' => 1800000000, 'refresh_token' => 'r', 'scopes' => ['a', 'b']]])
        ->and($store->get('user:1'))->toEqual($token);

    // Whatever else is in the cache under that key is ignored, not trusted.
    $cache->items[array_key_first($cache->items)] = 'O:8:"stdClass":0:{}';
    expect($store->get('user:1'))->toBeNull();
});
