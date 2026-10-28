<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Testing;

use DateTimeImmutable;
use JustSteveKing\SdkToolbox\Time\Sleeper;
use Psr\Clock\ClockInterface;

/**
 * A clock and a sleeper in one: sleeping moves the clock on, instantly,
 * and every sleep is recorded. Give the same instance to a connector as
 * both `clock` and `sleeper`.
 */
final class FakeTime implements ClockInterface, Sleeper
{
    private DateTimeImmutable $now;

    /** @var list<float> */
    private array $sleeps = [];

    public function __construct(string $now = '2026-11-16 09:00:00 UTC')
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->advance($seconds);
    }

    public function advance(float $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d microseconds', (int) round($seconds * 1_000_000)));
    }

    /**
     * @return list<float>
     */
    public function sleeps(): array
    {
        return $this->sleeps;
    }

    public function slept(): float
    {
        return array_sum($this->sleeps);
    }
}
