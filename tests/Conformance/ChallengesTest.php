<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Auth\Challenge;
use JustSteveKing\SdkToolbox\Auth\Challenges;

/**
 * RFC 9110 section 11.6.1 WWW-Authenticate, and RFC 6750 section 3's
 * Bearer challenges.
 */
it('reads an RFC 6750 invalid_token challenge', function (): void {
    expect(Challenges::parse('Bearer realm="example", error="invalid_token", error_description="The access token expired"'))->toEqual([
        new Challenge('bearer', ['realm' => 'example', 'error' => 'invalid_token', 'error_description' => 'The access token expired']),
    ]);
});

it('tells several challenges apart, though commas separate both', function (): void {
    $challenges = Challenges::parse('Basic realm="simple", Bearer realm="api", scope="a b", error=insufficient_scope, Newauth');

    expect(array_map(fn (Challenge $c): string => $c->scheme, $challenges))->toBe(['basic', 'bearer', 'newauth'])
        ->and($challenges[1]->parameter('scope'))->toBe('a b')
        ->and($challenges[1]->parameter('ERROR'))->toBe('insufficient_scope');
});

it('reads a token68 challenge', function (): void {
    expect(Challenges::parse('Negotiate YIIBhgYGKwYBBQUCoIIBejCCAXag=='))->toEqual([new Challenge('negotiate', [], 'YIIBhgYGKwYBBQUCoIIBejCCAXag==')]);
});

it('unescapes quoted values', function (): void {
    expect(Challenges::parse('Bearer realm="say \"hi\", ok"')[0]->parameter('realm'))->toBe('say "hi", ok');
});

it('reads a bare scheme', function (): void {
    expect(Challenges::parse('Bearer'))->toEqual([new Challenge('bearer')]);
});
