<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Observability\TraceContext;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

/**
 * W3C Trace Context Level 1.
 */
const INCOMING = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

function traced(FakeClient $http, ?TraceContext $tracing): Connector
{
    return new Connector('https://api.example.com', http: $http, tracing: $tracing);
}

it('joins the current trace with a new parent-id for every request (3.2.2.3)', function (): void {
    $http = new FakeClient(FakeResponse::empty(200), FakeResponse::empty(200));
    $connector = traced($http, new TraceContext(fn (): array => [INCOMING, 'rojo=00f067aa0ba902b7']));

    $connector->response(ping());
    $connector->response(ping());

    [$first, $second] = array_map(fn ($r): string => $r->getHeaderLine('traceparent'), $http->sent());

    expect($first)->toMatch('/^00-0af7651916cd43dd8448eb211c80319c-[0-9a-f]{16}-01$/')
        ->and($first)->not->toContain('b7ad6b7169203331')
        ->and(explode('-', $first)[2])->not->toBe(explode('-', $second)[2])
        ->and($http->last()?->getHeaderLine('tracestate'))->toBe('rojo=00f067aa0ba902b7');
});

it('starts a trace when there is none', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));

    traced($http, new TraceContext)->response(ping());

    expect($http->last()?->getHeaderLine('traceparent'))->toMatch('/^00-[0-9a-f]{32}-[0-9a-f]{16}-00$/');
});

it('leaves a request that already has a traceparent alone', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));

    traced($http, new TraceContext(fn (): array => [INCOMING]))->withHeaders(['traceparent' => '00-11111111111111111111111111111111-2222222222222222-01'])->response(ping());

    expect($http->last()?->getHeaderLine('traceparent'))->toBe('00-11111111111111111111111111111111-2222222222222222-01');
});

it('validates traceparent the way the Recommendation does (3.2)', function (string $value, ?array $expected): void {
    expect(TraceContext::parse($value))->toBe($expected);
})->with([
    'valid' => [INCOMING, ['0af7651916cd43dd8448eb211c80319c', '01']],
    'version ff' => ['ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01', null],
    'all-zero trace-id' => ['00-00000000000000000000000000000000-b7ad6b7169203331-01', null],
    'all-zero parent-id' => ['00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01', null],
    'too short' => ['00-0af7651916cd43dd-b7ad6b7169203331-01', null],
    'extra fields on version 00' => [INCOMING.'-what', null],
    'a future version with more fields' => ['cc-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01-what', ['0af7651916cd43dd8448eb211c80319c', '01']],
]);
