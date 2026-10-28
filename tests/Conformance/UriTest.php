<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Http\Uri;

/**
 * RFC 3986 section 5.4: every example, against the base it gives.
 */
it('resolves the normal examples (5.4.1)', function (string $reference, string $expected): void {
    expect(Uri::parse($reference, Uri::parse('http://a/b/c/d;p?q'))?->toString())->toBe($expected);
})->with([
    ['g:h', 'g:h'], ['g', 'http://a/b/c/g'], ['./g', 'http://a/b/c/g'], ['g/', 'http://a/b/c/g/'],
    ['/g', 'http://a/g'], ['//g', 'http://g'], ['?y', 'http://a/b/c/d;p?y'], ['g?y', 'http://a/b/c/g?y'],
    ['#s', 'http://a/b/c/d;p?q#s'], ['g#s', 'http://a/b/c/g#s'], ['g?y#s', 'http://a/b/c/g?y#s'], [';x', 'http://a/b/c/;x'],
    ['g;x', 'http://a/b/c/g;x'], ['g;x?y#s', 'http://a/b/c/g;x?y#s'], ['', 'http://a/b/c/d;p?q'], ['.', 'http://a/b/c/'],
    ['./', 'http://a/b/c/'], ['..', 'http://a/b/'], ['../', 'http://a/b/'], ['../g', 'http://a/b/g'],
    ['../..', 'http://a/'], ['../../', 'http://a/'], ['../../g', 'http://a/g'],
]);

it('resolves the abnormal examples (5.4.2)', function (string $reference, string $expected): void {
    expect(Uri::parse($reference, Uri::parse('http://a/b/c/d;p?q'))?->toString())->toBe($expected);
})->with([
    ['../../../g', 'http://a/g'], ['../../../../g', 'http://a/g'], ['/./g', 'http://a/g'], ['/../g', 'http://a/g'],
    ['g.', 'http://a/b/c/g.'], ['.g', 'http://a/b/c/.g'], ['g..', 'http://a/b/c/g..'], ['..g', 'http://a/b/c/..g'],
    ['./../g', 'http://a/b/g'], ['./g/.', 'http://a/b/c/g/'], ['g/./h', 'http://a/b/c/g/h'], ['g/../h', 'http://a/b/c/h'],
    ['g;x=1/./y', 'http://a/b/c/g;x=1/y'], ['g;x=1/../y', 'http://a/b/c/y'], ['g?y/./x', 'http://a/b/c/g?y/./x'],
    ['g?y/../x', 'http://a/b/c/g?y/../x'], ['g#s/./x', 'http://a/b/c/g#s/./x'], ['g#s/../x', 'http://a/b/c/g#s/../x'],
    ['http:g', 'http:g'],
]);

it('parses the parts and lowercases scheme and host', function (): void {
    $uri = Uri::parse('HTTPS://me:pw@API.Example.com:8443/v2/x?a=1#f');

    expect([$uri?->getScheme(), $uri?->getUserInfo(), $uri?->getHost(), $uri?->getPort(), $uri?->getPath(), $uri?->getQuery(), $uri?->getFragment()])
        ->toBe(['https', 'me:pw', 'api.example.com', 8443, '/v2/x', 'a=1', 'f']);
});

it('refuses what is not RFC 3986', function (string $uri): void {
    expect(Uri::parse($uri))->toBeNull();
})->with(['a space' => ['/a b'], 'a stray percent' => ['/a%zz'], 'a bad scheme' => ['1http://x'], 'a bad port' => ['http://x:port/']]);
