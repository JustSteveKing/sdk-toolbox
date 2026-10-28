<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Observability;

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Middleware\Middleware;
use JustSteveKing\SdkToolbox\Middleware\Next;
use JustSteveKing\SdkToolbox\Redaction\Redactor;
use NoDiscard;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An OpenTelemetry client span for every attempt, following the stable
 * HTTP semantic conventions, and trace context propagated from it.
 *
 * - One span per attempt, as the conventions say instrumentations SHOULD,
 *   with `http.request.resend_count` on retries.
 * - Named `{method}`: the raw path would make span names unbounded.
 * - `url.full` is redacted: query credentials never reach a trace backend.
 * - A 4xx or 5xx, or no response, sets the span's status to Error, with
 *   `error.type` as the status code or the exception class.
 *
 * Needs open-telemetry/api; without an SDK configured it's a no-op, as
 * OpenTelemetry's API always is. Pass a TracerProvider and propagator, or
 * it uses OpenTelemetry's globals.
 */
final readonly class OpenTelemetrySpans implements Middleware
{
    private TracerProviderInterface $tracers;

    private TextMapPropagatorInterface $propagator;

    public function __construct(
        ?TracerProviderInterface $tracers = null,
        ?TextMapPropagatorInterface $propagator = null,
        private Redactor $redactor = new Redactor,
        private ?Attempts $attempts = null,
    ) {
        $this->tracers = $tracers ?? Globals::tracerProvider();
        $this->propagator = $propagator ?? Globals::propagator();
    }

    #[NoDiscard]
    public function withRedactor(Redactor $redactor): self
    {
        return new self($this->tracers, $this->propagator, $redactor, $this->attempts);
    }

    #[NoDiscard]
    public function withAttempts(Attempts $attempts): self
    {
        return new self($this->tracers, $this->propagator, $this->redactor, $attempts);
    }

    public function handle(RequestInterface $request, Next $next): ResponseInterface
    {
        $method = strtoupper($request->getMethod()) ?: 'HTTP';
        $uri = $request->getUri();
        $resends = $this->attempts === null ? 0 : $this->attempts->current - 1;

        $span = $this->tracers
            ->getTracer('juststeveking/sdk-toolbox', Connector::VERSION)
            ->spanBuilder($method)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.request.method', $method)
            ->setAttribute('server.address', $uri->getHost())
            ->setAttribute('server.port', $uri->getPort() ?? ($uri->getScheme() === 'http' ? 80 : 443))
            ->setAttribute('url.full', $this->redactor->uri((string) $uri))
            ->startSpan();

        if ($resends > 0) {
            $span->setAttribute('http.request.resend_count', $resends);
        }

        $carrier = [];
        $this->propagator->inject($carrier, null, $span->storeInContext(Context::getCurrent()));

        foreach (is_array($carrier) ? $carrier : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $request = $request->withHeader($name, $value);
            }
        }

        try {
            $response = $next->handle($request);
        } catch (ClientExceptionInterface $e) {
            $span->setAttribute('error.type', $e::class)->setStatus(StatusCode::STATUS_ERROR);
            $span->end();

            throw $e;
        }

        $status = $response->getStatusCode();
        $span->setAttribute('http.response.status_code', $status);

        if ($status >= 400) {
            $span->setAttribute('error.type', (string) $status)->setStatus(StatusCode::STATUS_ERROR);
        }

        $span->end();

        return $response;
    }
}
