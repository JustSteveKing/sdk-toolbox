<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;
use JustSteveKing\SdkToolbox\Http\Method;
use JustSteveKing\SdkToolbox\Http\QueryString;

it('encodes the way APIs expect', function (): void {
    expect(QueryString::build([
        'q' => 'coffee shops',
        'page' => 2,
        'active' => true,
        'archived' => false,
        'missing' => null,
        'method' => Method::Get,
        'ids' => [1, 2],
    ]))->toBe('q=coffee%20shops&page=2&active=true&archived=false&method=GET&ids%5B0%5D=1&ids%5B1%5D=2');
});

it('refuses values it cannot encode', function (): void {
    expect(fn () => QueryString::build(['when' => new DateTimeImmutable]))->toThrow(InvalidRequestException::class);
});
