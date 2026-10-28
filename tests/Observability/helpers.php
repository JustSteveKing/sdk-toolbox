<?php

declare(strict_types=1);

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(fn (array $r): string => "{$r['level']}: {$r['message']}", $this->records);
    }
}

final class CollectingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, fn (object $e): bool => $e instanceof $class));
    }
}
