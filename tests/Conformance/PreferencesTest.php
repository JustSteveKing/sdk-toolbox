<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\Preferences;
use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Requests\HasPreferences;
use JustSteveKing\SdkToolbox\Requests\Request;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;

/**
 * RFC 7240 Prefer.
 */
it('writes the Prefer header (section 2)', function (): void {
    expect(Preferences::none()->returnMinimal()->wait(10)->respondAsync()->handling(strict: true)->header())
        ->toBe('return=minimal, wait=10, respond-async, handling=strict')
        ->and(Preferences::none()->with('X-Note', 'two words')->header())->toBe('x-note="two words"');
});

it('lets a later value of the same preference win', function (): void {
    expect(Preferences::none()->returnMinimal()->returnRepresentation()->header())->toBe('return=representation');
});

it('refuses names that are not tokens', function (): void {
    expect(fn () => Preferences::none()->with('bad name', null))->toThrow(InvalidRequestException::class);
});

it('sends preferences and reads Preference-Applied (section 3)', function (): void {
    $http = new FakeClient(FakeResponse::empty(204, ['Preference-Applied' => 'return=minimal, respond-async']));
    $request = new readonly class implements HasPreferences, Request
    {
        public function method(): Method
        {
            return Method::Post;
        }

        public function path(): string
        {
            return '/things';
        }

        public function preferences(): Preferences
        {
            return Preferences::none()->returnMinimal();
        }

        public function result(Response $response): Response
        {
            return $response;
        }
    };

    $response = (new Connector('https://api.example.com', http: $http))->send($request);

    expect($http->last()?->getHeaderLine('Prefer'))->toBe('return=minimal')
        ->and($response->preferenceApplied())->toBe(['return' => 'minimal', 'respond-async' => null]);
});
