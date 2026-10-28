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
            array_any($this->sent, $matches),
            'No request matching the given callback was sent.',
        );
    }

    /**
     * @param  Closure(RequestInterface): bool  $matches
     */
    public function assertNotSent(Closure $matches): void
    {
        self::assert(
            ! array_any($this->sent, $matches),
            'A request matching the given callback was sent.',
        );
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
