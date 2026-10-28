<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Time;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The real time, for when no PSR-20 clock is given.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
