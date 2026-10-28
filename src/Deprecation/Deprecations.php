<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Deprecation;

use Closure;
use JustSteveKing\SdkToolbox\Errors\DeprecatedEndpointException;
use JustSteveKing\SdkToolbox\Events\DeprecationNoticed;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Observability\Telemetry;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Tells you when the API says an endpoint is deprecated or being sunset.
 *
 * The handler runs once per method and path per process, not on every
 * request, so a loop over a deprecated endpoint doesn't flood a log. The
 * response is passed on unchanged.
 *
 *     deprecations: new Deprecations(fn (DeprecationNotice $n) => $logger->warning($n->describe())),
 *     deprecations: Deprecations::strict(),   // in an SDK's own tests
 */
final class Deprecations implements Middleware
{
    /** @var array<string, true> */
    private array $seen = [];

    private Telemetry $telemetry;

    /**
     * @param  (Closure(DeprecationNotice): void)|null  $handler  null to only log and dispatch it
     */
    public function __construct(
        private readonly ?Closure $handler = null,
        private readonly Redactor $redactor = new Redactor,
    ) {
        $this->telemetry = new Telemetry;
    }

    /**
     * The connector calls this, so notices are logged and dispatched too.
     */
    public function useTelemetry(Telemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
    }

    /**
     * Throws DeprecatedEndpointException for the first response that
     * carries a notice.
     */
    public static function strict(): self
    {
        return new self(static fn (DeprecationNotice $notice): never => throw new DeprecatedEndpointException($notice));
    }

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $response = $next->handle($request);
        $notice = DeprecationNotice::from($request, $response, $this->redactor);

        if ($notice !== null) {
            $key = $notice->method.' '.$request->getUri()->getPath();

            if (! isset($this->seen[$key])) {
                $this->seen[$key] = true;
                $this->telemetry->emit(new DeprecationNoticed($notice));

                if ($this->handler !== null) {
                    ($this->handler)($notice);
                }
            }
        }

        return $response;
    }
}
