<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Bodies\Body;
use JustSteveKing\SdkToolbox\Bodies\JsonPatch;
use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Errors\PreconditionFailedException;
use JustSteveKing\SdkToolbox\Errors\PreconditionRequiredException;
use JustSteveKing\SdkToolbox\Http\EntityTag;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Precondition;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\Conditional;
use JustSteveKing\SdkToolbox\Requests\HasBody;
use JustSteveKing\SdkToolbox\Requests\Request;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

/**
 * RFC 9110 sections 8.8.3 and 13.
 *
 * @return Request<Response>
 */
function patchIf(?Precondition $precondition): Request
{
    return new readonly class($precondition) implements Conditional, HasBody, Request
    {
        public function __construct(private ?Precondition $precondition) {}

        public function method(): Method
        {
            return Method::Patch;
        }

        public function path(): string
        {
            return '/projects/1';
        }

        public function body(): Body
        {
            return JsonPatch::new()->replace('/name', 'Docs');
        }

        public function precondition(): ?Precondition
        {
            return $this->precondition;
        }

        public function result(Response $response): Response
        {
            return $response;
        }
    };
}

it('parses strong and weak entity tags (8.8.3)', function (): void {
    expect(EntityTag::parse('"xyzzy"'))->toEqual(new EntityTag('xyzzy'))
        ->and(EntityTag::parse('W/"xyzzy"'))->toEqual(new EntityTag('xyzzy', weak: true))
        ->and(EntityTag::parse('""'))->toEqual(new EntityTag(''))
        ->and(EntityTag::parse('xyzzy'))->toBeNull()
        ->and(EntityTag::parse('"a"b"'))->toBeNull();
});

it('compares strongly: weak tags never match (8.8.3.2)', function (): void {
    expect((new EntityTag('1'))->matchesStrongly(new EntityTag('1')))->toBeTrue()
        ->and((new EntityTag('1', true))->matchesStrongly(new EntityTag('1', true)))->toBeFalse()
        ->and((new EntityTag('1'))->matchesStrongly(new EntityTag('2')))->toBeFalse();
});

it('guards an update with the ETag it read', function (): void {
    $http = new FakeClient(FakeResponse::json(['name' => 'Website'], headers: ['ETag' => '"v7"']), FakeResponse::empty(204));
    $connector = new Connector('https://api.example.com', http: $http);

    $etag = $connector->response(ping())->etag();
    $connector->send(patchIf(Precondition::ifMatch($etag ?? '')));

    expect($http->last()?->getHeaderLine('If-Match'))->toBe('"v7"')
        ->and($http->last()?->getHeaderLine('Content-Type'))->toBe('application/json-patch+json');
});

it('refuses a weak tag in If-Match, which could never match (13.1.1)', function (): void {
    expect(fn () => Precondition::ifMatch('W/"v7"'))->toThrow(InvalidRequestException::class, 'strong comparison');
});

it('builds the other preconditions', function (): void {
    expect(Precondition::ifMatch('"a"', new EntityTag('b'))->headers)->toBe(['If-Match' => '"a", "b"'])
        ->and(Precondition::ifExists()->headers)->toBe(['If-Match' => '*'])
        ->and(Precondition::ifNoneMatchAny()->headers)->toBe(['If-None-Match' => '*'])
        ->and(Precondition::ifUnmodifiedSince(new DateTimeImmutable('2026-11-16 09:00:00 UTC'))->headers)->toBe(['If-Unmodified-Since' => 'Mon, 16 Nov 2026 09:00:00 GMT']);
});

it('throws 412 and 428 as their own exceptions', function (): void {
    $connector = new Connector('https://api.example.com', http: new FakeClient(FakeResponse::empty(412), FakeResponse::empty(428)));

    expect(fn () => $connector->send(patchIf(Precondition::ifMatch('"old"'))))->toThrow(PreconditionFailedException::class)
        ->and(fn () => $connector->send(patchIf(null)))->toThrow(PreconditionRequiredException::class);
});
