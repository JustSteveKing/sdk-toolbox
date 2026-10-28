<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Time;

use DateTimeInterface;

/**
 * @internal
 */
final class Seconds
{
    public static function between(DateTimeInterface $from, DateTimeInterface $to): float
    {
        return (float) $to->format('U.u') - (float) $from->format('U.u');
    }
}
