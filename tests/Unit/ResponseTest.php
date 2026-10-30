<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\InvalidResponseException;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use Nyholm\Psr7\Request;

function response(Psr\Http\Message\ResponseInterface $psr): Response
{
    return new Response($psr, new Request('GET', 'https://api.example.com/things'));
}

it('reads the media type without parameters', function (): void {
    expect(response(FakeResponse::text('x', headers: ['Content-Type' => 'Application/JSON; charset=utf-8']))->contentType())
        ->toBe('application/json');
});

it('decodes JSON objects and arrays', function (): void {
    expect(response(FakeResponse::json(['a' => 1]))->json())->toBe(['a' => 1])
        ->and(response(FakeResponse::json([1, 2]))->json())->toBe([1, 2]);
});

it('refuses a body that is not a JSON object or array', function (string $body): void {
    expect(fn () => response(FakeResponse::text($body))->json())->toThrow(InvalidResponseException::class);
})->with(['not json' => ['<html>'], 'a scalar' => ['"text"'], 'empty' => ['']]);

it('knows success from failure', function (): void {
    expect(response(FakeResponse::empty(204))->successful())->toBeTrue()
        ->and(response(FakeResponse::empty(304))->failed())->toBeFalse()
        ->and(response(FakeResponse::empty(404))->failed())->toBeTrue();
});

it('collects the body, or the part at a dotted path', function (): void {
    $response = response(FakeResponse::json(['data' => [['id' => 'a'], ['id' => 'b']], 'meta' => ['total' => 2]]));

    expect($response->collect('data')->pluck('id')->all())->toBe(['a', 'b'])
        ->and($response->collect()->keys()->all())->toBe(['data', 'meta'])
        ->and($response->collect('missing')->isEmpty())->toBeTrue()
        ->and($response->collect('meta.total')->all())->toBe([2]);
});
