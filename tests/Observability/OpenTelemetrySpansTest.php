<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\QueryKey;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Observability\OpenTelemetrySpans;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * The stable OpenTelemetry HTTP client span conventions.
 *
 * @return array{Connector, InMemoryExporter}
 */
function instrumented(FakeClient $http): array
{
    $exporter = new InMemoryExporter;
    $time = new FakeTime;
    $spans = new OpenTelemetrySpans(new TracerProvider(new SimpleSpanProcessor($exporter)), TraceContextPropagator::getInstance());

    return [new Connector('https://api.example.com:8443', auth: new QueryKey('api_key', 'top-secret'), http: $http, clock: $time, sleeper: $time, tracing: $spans), $exporter];
}

/**
 * @return list<SpanDataInterface>
 */
function spansOf(InMemoryExporter $exporter): array
{
    /** @var list<SpanDataInterface> */
    return array_values($exporter->getSpans());
}

it('records a client span with the required attributes, url redacted', function (): void {
    [$connector, $exporter] = instrumented(new FakeClient(FakeResponse::empty(200)));

    $connector->response(ping(['q' => 'coffee']));

    $span = spansOf($exporter)[0];
    expect($span->getName())->toBe('GET')
        ->and($span->getKind())->toBe(SpanKind::KIND_CLIENT)
        ->and($span->getAttributes()->toArray())->toBe([
            'http.request.method' => 'GET',
            'server.address' => 'api.example.com',
            'server.port' => 8443,
            'url.full' => 'https://api.example.com:8443/ping?q=coffee&api_key=%5Bredacted%5D',
            'http.response.status_code' => 200,
        ])
        ->and($span->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET);
});

it('records one span per attempt, with the resend count', function (): void {
    [$connector, $exporter] = instrumented(new FakeClient(FakeResponse::empty(503), FakeResponse::empty(200)));

    $connector->response(ping());

    $spans = spansOf($exporter);
    expect($spans)->toHaveCount(2)
        ->and($spans[0]->getAttributes()->get('http.request.resend_count'))->toBeNull()
        ->and($spans[0]->getAttributes()->get('error.type'))->toBe('503')
        ->and($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans[1]->getAttributes()->get('http.request.resend_count'))->toBe(1);
});

it('marks a network failure with the exception type', function (): void {
    [$connector, $exporter] = instrumented(new FakeClient(...array_fill(0, 3, FakeResponse::networkError())));

    try {
        $connector->response(ping());
    } catch (JustSteveKing\SdkToolbox\Errors\TransportException) {
    }

    expect(spansOf($exporter))->toHaveCount(3)
        ->and(spansOf($exporter)[0]->getAttributes()->get('error.type'))->toBe(JustSteveKing\SdkToolbox\Testing\FakeNetworkException::class);
});

it('propagates the span\'s own context in traceparent', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));
    [$connector, $exporter] = instrumented($http);

    $connector->response(ping());

    $span = spansOf($exporter)[0];
    expect($http->last()?->getHeaderLine('traceparent'))->toBe("00-{$span->getTraceId()}-{$span->getSpanId()}-01");
});
