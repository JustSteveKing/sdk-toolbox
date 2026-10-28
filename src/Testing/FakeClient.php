<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

use AssertionError;
use Closure;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client for tests: answers from a queue, in order, and records
 * what was sent.
 *
 * A request with nothing left in the queue throws StrayRequestException,
 * so a test can't quietly reach the network or pass on a default. Pass the
 * fake to your SDK the way you'd pass a real client.
 *
 *     $http = new FakeClient(FakeResponse::json(['id' => 'app_1']));
 *     $sdk = new Sevalla('token', http: $http);
 *
 *     $sdk->applications()->get('app_1');
 *
 *     $http->assertSent(fn ($request) => $request->getUri()->getPath() === '/v2/applications/app_1');
 */
final class FakeClient implements ClientInterface
{
    /** @var list<ResponseInterface|Closure(RequestInterface): ResponseInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    private array $sent = [];

    /**
     * @param  ResponseInterface|Closure(RequestInterface): ResponseInterface  ...$responses
     */
    public function __construct(ResponseInterface|Closure ...$responses)
    {
        $this->queue = array_values($responses);
    }

    /**
     * Adds answers to the end of the queue.
     *
     * @param  ResponseInterface|Closure(RequestInterface): ResponseInterface  ...$responses
     */
    public function push(ResponseInterface|Closure ...$responses): self
    {
        $this->queue = [...$this->queue, ...array_values($responses)];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new StrayRequestException("No fake response left for {$request->getMethod()} {$request->getUri()->getPath()}.");
        }

        return $next instanceof Closure ? $next($request) : $next;
    }

    /**
     * @return list<RequestInterface>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function last(): ?RequestInterface
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }

    /**
     * @param  Closure(RequestInterface): bool  $matches
     */
    public function assertSent(Closure $matches): void
    {
        self::assert(
            self::any($this->sent, $matches),
            'No request matching the given callback was sent.',
        );
    }

    /**
     * @param  Closure(RequestInterface): bool  $matches
     */
    public function assertNotSent(Closure $matches): void
    {
        self::assert(
            ! self::any($this->sent, $matches),
            'A request matching the given callback was sent.',
        );
    }

    /**
     * A request with this method and path was sent.
     */
    public function assertSentTo(string $method, string $path): void
    {
        self::assert(
            self::any($this->sent, static fn (RequestInterface $r): bool => strcasecmp($r->getMethod(), $method) === 0 && $r->getUri()->getPath() === $path),
            "No {$method} {$path} was sent.",
        );
    }

    /**
     * A request was sent whose JSON body contains everything given, at the
     * same keys: a subset match, so unrelated fields don't matter.
     *
     * @param  array<array-key, mixed>  $subset
     */
    public function assertSentJson(array $subset): void
    {
        self::assert(
            self::any($this->sent, static function (RequestInterface $r) use ($subset): bool {
                $body = json_decode((string) $r->getBody(), true);
                $r->getBody()->rewind();

                return is_array($body) && self::contains($body, $subset);
            }),
            'No request was sent with that JSON in its body.',
        );
    }

    /**
     * Requests went out in this order, as `METHOD /path` strings; others
     * may come between them.
     *
     * @param  list<string>  $expected
     */
    public function assertSentInOrder(array $expected): void
    {
        $sent = array_map(static fn (RequestInterface $r): string => "{$r->getMethod()} {$r->getUri()->getPath()}", $this->sent);
        $position = 0;

        foreach ($sent as $line) {
            if ($line === ($expected[$position] ?? null)) {
                $position++;
            }
        }

        self::assert($position === count($expected), 'Requests weren\'t sent in the expected order. Sent: '.implode(', ', $sent));
    }

    public function assertSentCount(int $count): void
    {
        self::assert(count($this->sent) === $count, sprintf('Expected %d requests, %d were sent.', $count, count($this->sent)));
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }

    /**
     * Every queued answer was used.
     */
    public function assertQueueEmpty(): void
    {
        self::assert($this->queue === [], sprintf('%d fake responses were never used.', count($this->queue)));
    }

    /**
     * array_any(), which PHP only has from 8.4.
     *
     * @param  list<RequestInterface>  $requests
     * @param  Closure(RequestInterface): bool  $matches
     */
    private static function any(array $requests, Closure $matches): bool
    {
        foreach ($requests as $request) {
            if ($matches($request)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $haystack
     * @param  array<array-key, mixed>  $subset
     */
    private static function contains(array $haystack, array $subset): bool
    {
        foreach ($subset as $key => $value) {
            if (! array_key_exists($key, $haystack)) {
                return false;
            }

            $matches = is_array($value) && is_array($haystack[$key])
                ? self::contains($haystack[$key], $value)
                : $haystack[$key] === $value;

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private static function assert(bool $condition, string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue($condition, $message);

            return;
        }

        if (! $condition) {
            throw new AssertionError($message);
        }
    }
}
