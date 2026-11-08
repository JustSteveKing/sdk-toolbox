<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Redaction\Redactor;
use Nyholm\Psr7\Request;

it('masks sensitive query parameters and user info', function (): void {
    expect((new Redactor)->uri('https://me:pw@api.example.com/search?q=coffee&api_key=abc123&Token=x'))
        ->toBe('https://api.example.com/search?q=coffee&api_key=%5Bredacted%5D&Token=%5Bredacted%5D');
});

it('masks sensitive headers, whatever their case', function (): void {
    $request = new Request('GET', 'https://api.example.com', ['Authorization' => 'Bearer abc', 'X-Custom-Key' => 'k', 'Accept' => 'application/json']);

    expect((new Redactor(headers: ['x-custom-key']))->headers($request))->toBe([
        'Host' => ['api.example.com'],
        'Authorization' => ['[redacted]'],
        'X-Custom-Key' => ['[redacted]'],
        'Accept' => ['application/json'],
    ]);
});

it('masks the raw URI wherever a client quoted it', function (): void {
    expect((new Redactor)->message('Timeout for "https://api.example.com/search?q=a&api_key=abc123" (api_key=abc123)'))
        ->toBe('Timeout for "https://api.example.com/search?q=a&api_key=%5Bredacted%5D" (api_key=%5Bredacted%5D)');
});

it('masks common pre-signed URL signature parameters, whatever their case', function (): void {
    expect((new Redactor)->uri('https://uploads.storage.test/f?X-Amz-Credential=AKID&X-Amz-Signature=a&x-amz-security-token=b&X-Goog-Signature=c&X-Goog-Credential=d&Signature=e&sig=f&expires=600'))
        ->toBe('https://uploads.storage.test/f?X-Amz-Credential=%5Bredacted%5D&X-Amz-Signature=%5Bredacted%5D&x-amz-security-token=%5Bredacted%5D&X-Goog-Signature=%5Bredacted%5D&X-Goog-Credential=%5Bredacted%5D&Signature=%5Bredacted%5D&sig=%5Bredacted%5D&expires=600')
        ->and((new Redactor)->message('PUT https://uploads.storage.test/f?X-Amz-Signature=abc123 timed out'))
        ->toBe('PUT https://uploads.storage.test/f?X-Amz-Signature=%5Bredacted%5D timed out');
});
