<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Observability;

use JustSteveKing\SdkToolbox\Events\Event;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use JustSteveKing\SdkToolbox\Redaction\RequestSnapshot;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Where events go: a PSR-14 dispatcher, a PSR-3 logger, both, or neither.
 *
 * The connector builds one and hands it to its own middleware, so an SDK
 * gets logging and events by passing a logger or a dispatcher and nothing
 * else.
 */
final readonly class Telemetry
{
    public function __construct(
        private ?EventDispatcherInterface $events = null,
        private ?LoggerInterface $logger = null,
        public Redactor $redactor = new Redactor,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function enabled(): bool
    {
        return $this->events !== null || $this->logger !== null;
    }

    public function emit(Event $event): void
    {
        $this->events?->dispatch($event);
        $this->logger?->log($event->level(), $event->message(), $event->context());
    }

    public function snapshot(RequestInterface $request): RequestSnapshot
    {
        return $this->redactor->request($request);
    }
}
