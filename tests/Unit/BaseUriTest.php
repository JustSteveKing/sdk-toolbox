<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\UnsafeUriException;
use JustSteveKing\SdkToolbox\Http\BaseUri;

it('appends paths to the base path rather than replacing it', function (string $path, string $expected): void {
    expect((new BaseUri('https://api.example.com/v2'))->resolve($path)->toString())->toBe($expected);
})->with([
    'leading slash' => ['/applications', 'https://api.example.com/v2/applications'],
    'no leading slash' => ['applications/1', 'https://api.example.com/v2/applications/1'],
    'trailing slash on base' => ['/a', 'https://api.example.com/v2/a'],
    'empty path' => ['', 'https://api.example.com/v2/'],
]);

it('works with a base that has no path', function (): void {
    expect((new BaseUri('https://api.example.com'))->resolve('/users')->toString())->toBe('https://api.example.com/users');
});

it('refuses paths that would leave the base', function (string $path): void {
    expect(fn () => (new BaseUri('https://api.example.com/v2'))->resolve($path))->toThrow(UnsafeUriException::class);
})->with([
    'another host' => ['//evil.example/steal'],
    'absolute URL' => ['https://evil.example/steal'],
    'other scheme' => ['javascript:alert(1)'],
    'dot segments' => ['../admin'],
    'dot segments mid-path' => ['/users/../../admin'],
    'encoded dot segments' => ['/users/%2e%2e/%2E%2E/admin'],
    'query in the path' => ['/users?admin=true'],
    'fragment in the path' => ['/users#x'],
    'not a URI' => ['/a b'],
]);

it('refuses a base URI it cannot pin', function (string $base): void {
    expect(fn () => new BaseUri($base))->toThrow(UnsafeUriException::class);
})->with([
    'relative' => ['/v2'],
    'no host' => ['https:///v2'],
    'with a query' => ['https://api.example.com/v2?key=1'],
    'with credentials' => ['https://user:pass@api.example.com'],
]);
