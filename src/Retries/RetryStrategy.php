<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Retries;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Decides whether an attempt is tried again, and after how long.
 */
interface RetryStrategy
{
    /**
     * @param  int  $attempt  the attempt that just finished, from 1
     * @param  float  $elapsed  seconds since the first attempt started
     * @return float|null seconds to wait before the next attempt, or null to stop
     */
    public function delay(
        RequestInterface $request,
        ?ResponseInterface $response,
        ?ClientExceptionInterface $error,
        int $attempt,
        float $elapsed,
        ClockInterface $clock,
    ): ?float;
}
