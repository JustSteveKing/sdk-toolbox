<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\JsonBody;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\DigestMismatchException;
use JustSteveKing\SdkToolbox\Integrity\ContentDigest;
use JustSteveKing\SdkToolbox\Integrity\DigestAlgorithm;
use JustSteveKing\SdkToolbox\Integrity\Digests;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

/**
 * RFC 9530 Digest Fields.
 */
it('computes the digests in RFC 9421\'s test messages', function (): void {
    expect(ContentDigest::header('{"hello": "world"}', DigestAlgorithm::Sha512))
        ->toBe('sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:')
        ->and(ContentDigest::header('{"message": "good dog"}', DigestAlgorithm::Sha512))
        ->toBe('sha-512=:mEWXIS7MaLRuGgxOBdODa3xqM1XdEvxoYhvlCFJ41QJgJc4GTsPp29l5oGX69wWdXymyU0rjJuahq4l5aGgfLQ==:');
});

it('verifies a message against its digest', function (): void {
    expect(ContentDigest::verify(rfc9421Request()))->toBeTrue()
        ->and(ContentDigest::verify(rfc9421Request()->withBody(Nyholm\Psr7\Stream::create('{"hello": "there"}'))))->toBeFalse();
});

it('checks only Active algorithms (section 5), and says when there was nothing to check', function (): void {
    expect(ContentDigest::verify(FakeResponse::text('x', headers: ['Content-Digest' => 'md5=:AAAA:'])))->toBeNull()
        ->and(ContentDigest::verify(FakeResponse::text('x')))->toBeNull()
        ->and(ContentDigest::verify(FakeResponse::text('x', headers: ['Content-Digest' => 'not a dictionary ='])))->toBeNull()
        ->and(ContentDigest::verify(FakeResponse::text('x', headers: ['Content-Digest' => 'md5=:AAAA:, '.ContentDigest::header('x')])))->toBeTrue();
});

it('adds Content-Digest to requests with a body, and checks responses', function (): void {
    $http = new FakeClient(FakeResponse::json(['ok' => true], headers: ['Content-Digest' => ContentDigest::header('{"ok":true}')]));
    $connector = new Connector('https://api.example.com', http: $http)->withMiddleware(new Digests);

    $connector->send(post(new JsonBody(['amount' => 5])));

    expect($http->last()?->getHeaderLine('Content-Digest'))->toBe(ContentDigest::header('{"amount":5}'));
});

it('leaves bodiless requests alone', function (): void {
    $http = new FakeClient(FakeResponse::empty(200));

    new Connector('https://api.example.com', http: $http)->withMiddleware(new Digests)->response(ping());

    expect($http->last()?->hasHeader('Content-Digest'))->toBeFalse();
});

it('throws when a response does not match its digest', function (): void {
    $http = new FakeClient(FakeResponse::json(['ok' => true], headers: ['Content-Digest' => ContentDigest::header('something else')]));

    expect(fn () => new Connector('https://api.example.com', http: $http)->withMiddleware(new Digests)->response(ping()))
        ->toThrow(DigestMismatchException::class);
});

it('does not check a response a client may have decoded', function (): void {
    $http = new FakeClient(FakeResponse::json(['ok' => true], headers: ['Content-Encoding' => 'gzip', 'Content-Digest' => ContentDigest::header('gzipped bytes')]));

    expect(new Connector('https://api.example.com', http: $http)->withMiddleware(new Digests)->response(ping())->status())->toBe(200);
});
