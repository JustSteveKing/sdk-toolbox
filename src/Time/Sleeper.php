<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Time;

/**
 * Waits. Separate from the PSR-20 clock so tests can wait without waiting.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
