<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Connector;
use JustSteveKing\SdkToolbox\Deprecation\DeprecationNotice;
use JustSteveKing\SdkToolbox\Deprecation\Deprecations;
use JustSteveKing\SdkToolbox\Errors\DeprecatedEndpointException;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use Nyholm\Psr7\Request;

/**
 * RFC 9745 Deprecation and RFC 8594 Sunset.
 */
function notice(array $headers): ?DeprecationNotice
{
    return DeprecationNotice::from(new Request('GET', 'https://api.example.com/v1/users?api_key=secret'), FakeResponse::empty(200, $headers));
}

it('reads RFC 9745\'s structured date, Sunset\'s HTTP-date and both links', function (): void {
    $notice = notice([
        'Deprecation' => '@1688169599',
        'Sunset' => 'Wed, 11 Nov 2026 11:11:11 GMT',
        'Link' => '<https://developer.example.com/deprecation>; rel="deprecation"; type="text/html", </policies/sunset>; rel="sunset"',
    ]);

    expect($notice?->deprecatedAt)->toEqual(new DateTimeImmutable('@1688169599'))
        ->and($notice?->sunsetAt)->toEqual(new DateTimeImmutable('2026-11-11 11:11:11 UTC'))
        ->and($notice?->deprecationLink)->toBe('https://developer.example.com/deprecation')
        ->and($notice?->sunsetLink)->toBe('https://api.example.com/policies/sunset')
        ->and($notice?->uri)->toBe('https://api.example.com/v1/users?api_key=%5Bredacted%5D')
        ->and($notice?->describe())->toBe('GET https://api.example.com/v1/users?api_key=%5Bredacted%5D is deprecated as of 2023-06-30 and stops responding after 2026-11-11 (see https://developer.example.com/deprecation).');
});

it('reads Sunset on its own', function (): void {
    expect(notice(['Sunset' => 'Wed, 11 Nov 2026 11:11:11 GMT'])?->deprecatedAt)->toBeNull();
});

it('accepts the undated form earlier drafts used', function (): void {
    expect(notice(['Deprecation' => 'true']))->not->toBeNull()
        ->and(notice(['Deprecation' => '?1'])?->deprecatedAt)->toBeNull();
});

it('ignores what is neither', function (array $headers): void {
    expect(notice($headers))->toBeNull();
})->with([
    'nothing' => [[]],
    'not a date' => [['Deprecation' => 'soon']],
    'a bare integer' => [['Deprecation' => '1688169599']],
    'an invalid Sunset' => [['Sunset' => 'next year']],
]);

it('knows when the sunset has passed', function (): void {
    $notice = notice(['Sunset' => 'Wed, 11 Nov 2026 11:11:11 GMT']);

    expect($notice?->isSunset(new DateTimeImmutable('2026-11-12')))->toBeTrue()
        ->and($notice?->isSunset(new DateTimeImmutable('2026-11-10')))->toBeFalse();
});

it('tells the handler once per endpoint, and passes the response on', function (): void {
    $seen = [];
    $http = new FakeClient(...array_fill(0, 3, FakeResponse::empty(200, ['Deprecation' => '@1688169599'])));
    $connector = new Connector('https://api.example.com', http: $http, deprecations: new Deprecations(function (DeprecationNotice $n) use (&$seen): void {
        $seen[] = $n->uri;
    }));

    foreach (range(1, 3) as $ignored) {
        expect($connector->response(ping())->status())->toBe(200);
    }

    expect($seen)->toBe(['https://api.example.com/ping']);
});

it('fails an SDK\'s tests on a deprecated endpoint when strict', function (): void {
    $connector = new Connector('https://api.example.com', http: new FakeClient(FakeResponse::empty(200, ['Deprecation' => '@1688169599'])), deprecations: Deprecations::strict());

    expect(fn () => $connector->response(ping()))->toThrow(DeprecatedEndpointException::class, 'GET https://api.example.com/ping is deprecated');
});
