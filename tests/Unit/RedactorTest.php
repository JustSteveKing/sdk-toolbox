<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Redaction\Redactor;
use Nyholm\Psr7\Request;

it('masks sensitive query parameters and user info', function (): void {
    expect(new Redactor()->uri('https://me:pw@api.example.com/search?q=coffee&api_key=abc123&Token=x'))
        ->toBe('https://api.example.com/search?q=coffee&api_key=%5Bredacted%5D&Token=%5Bredacted%5D');
});

it('masks sensitive headers, whatever their case', function (): void {
    $request = new Request('GET', 'https://api.example.com', ['Authorization' => 'Bearer abc', 'X-Custom-Key' => 'k', 'Accept' => 'application/json']);

    expect(new Redactor(headers: ['x-custom-key'])->headers($request))->toBe([
        'Host' => ['api.example.com'],
        'Authorization' => ['[redacted]'],
        'X-Custom-Key' => ['[redacted]'],
        'Accept' => ['application/json'],
    ]);
});

it('masks the raw URI wherever a client quoted it', function (): void {
    expect(new Redactor()->message('Timeout for "https://api.example.com/search?q=a&api_key=abc123" (api_key=abc123)'))
        ->toBe('Timeout for "https://api.example.com/search?q=a&api_key=%5Bredacted%5D" (api_key=%5Bredacted%5D)');
});
