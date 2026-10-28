<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Retries;

use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Time\Seconds;
use JustSteveKing\SdkToolbox\Time\Sleeper;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Runs the rest of the pipeline again for as long as the strategy says.
 *
 * Everything after it, authentication included, runs on every attempt, so
 * a refreshed token or a throttle wait applies to each one. When the
 * strategy stops, the last response is returned, or the last error
 * thrown, exactly as if there had been no retries.
 *
 * A body that can't be rewound can't be sent twice, so a request with one
 * is never retried.
 */
final readonly class Retry implements Middleware
{
    public function __construct(
        private RetryStrategy $strategy,
        private ClockInterface $clock,
        private Sleeper $sleeper,
    ) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $started = $this->clock->now();
        $body = $request->getBody();

        for ($attempt = 1; ; $attempt++) {
            if ($attempt > 1) {
                $body->rewind();
            }

            $response = null;
            $error = null;

            try {
                $response = $next->handle($request);
            } catch (ClientExceptionInterface $e) {
                $error = $e;
            }

            $delay = $body->isSeekable()
                ? $this->strategy->delay($request, $response, $error, $attempt, Seconds::between($started, $this->clock->now()), $this->clock)
                : null;

            if ($delay === null) {
                if ($error !== null) {
                    throw $error;
                }

                /** @var ResponseInterface $response one or the other is always set */
                return $response;
            }

            $this->sleeper->sleep($delay);
        }
    }
}
