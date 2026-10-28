<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\ProblemDetails;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use Nyholm\Psr7\Request;

/**
 * RFC 9457, read from the consumer's side.
 */
function problemFrom(Psr\Http\Message\ResponseInterface $psr, string $uri = 'https://api.example.com/v2/orders/1?api_key=secret'): ?ProblemDetails
{
    return (new Response($psr, new Request('POST', $uri)))->problem();
}

it('reads the standard members', function (): void {
    $problem = problemFrom(FakeResponse::problem(403, 'https://example.com/probs/out-of-credit', 'Out of credit', 'Balance is 30.', ['balance' => 30, 'accounts' => ['/account/1']]));

    expect($problem)->toEqual(new ProblemDetails(
        type: 'https://example.com/probs/out-of-credit',
        title: 'Out of credit',
        status: 403,
        detail: 'Balance is 30.',
        extensions: ['balance' => 30, 'accounts' => ['/account/1']],
    ));
});

it('defaults type to about:blank when it is absent (3.1.1)', function (): void {
    expect(problemFrom(FakeResponse::text('{"title":"Oops"}', 500, ['Content-Type' => 'application/problem+json']))?->isBlank())->toBeTrue();
});

it('ignores members with the wrong JSON type (3.1)', function (): void {
    $problem = problemFrom(FakeResponse::text(
        '{"type":42,"title":["x"],"status":"404","detail":false,"instance":{}}',
        404,
        ['Content-Type' => 'application/problem+json'],
    ));

    expect($problem)->toEqual(new ProblemDetails);
});

it('resolves a relative type against the request URI, without its query (3.1.1)', function (): void {
    $problem = problemFrom(FakeResponse::problem(403, '/problems/out-of-credit'));

    expect($problem?->type)->toBe('https://api.example.com/problems/out-of-credit')
        ->and($problem?->type)->not->toContain('secret');
});

it('ignores an out-of-range status, which is only advisory anyway (3.1.2)', function (): void {
    $problem = problemFrom(FakeResponse::text('{"status":999}', 400, ['Content-Type' => 'application/problem+json']));

    expect($problem?->status)->toBeNull();
});

it('is only read from application/problem+json', function (): void {
    expect(problemFrom(FakeResponse::json(['type' => 'https://x', 'title' => 'x'], 400)))->toBeNull();
});

it('treats a body that is not a JSON object as no problem at all', function (string $body): void {
    expect(problemFrom(FakeResponse::text($body, 500, ['Content-Type' => 'application/problem+json'])))->toBeNull();
})->with(['not json' => ['<html>'], 'a list' => ['[1,2]'], 'a string' => ['"x"']]);
