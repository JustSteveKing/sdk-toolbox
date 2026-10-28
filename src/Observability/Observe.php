<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Observability;

use JustSteveKing\SdkToolbox\Events\RequestFailed;
use JustSteveKing\SdkToolbox\Events\RequestSending;
use JustSteveKing\SdkToolbox\Events\ResponseReceived;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Reports each attempt as it goes to the HTTP client: sending, then
 * received or failed, with how long it took on a monotonic clock.
 *
 * @internal the connector puts it in place, just before the client
 */
final readonly class Observe implements Middleware
{
    public function __construct(
        private Telemetry $telemetry,
        private Attempts $attempts,
    ) {}

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $snapshot = $this->telemetry->snapshot($request);
        $attempt = $this->attempts->current;
        $this->telemetry->emit(new RequestSending($snapshot, $attempt));
        $started = hrtime(true);

        try {
            $response = $next->handle($request);
        } catch (ClientExceptionInterface $e) {
            $this->telemetry->emit(new RequestFailed($snapshot, $this->telemetry->redactor->message($e->getMessage()), $e::class, self::since($started), $attempt));

            throw $e;
        }

        $this->telemetry->emit(new ResponseReceived($snapshot, $response, self::since($started), $attempt));

        return $response;
    }

    private static function since(int|float $started): float
    {
        return (hrtime(true) - $started) / 1e9;
    }
}
