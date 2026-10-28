<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Errors\SdkException;

arch('strict types everywhere')
    ->expect('JustSteveKing\SdkToolbox')
    ->toUseStrictTypes();

// Pest's toUse() autoloads every class under a namespace it's given, and
// Symfony's HttpClient has classes that refuse to load without optional
// packages, so this reads the source instead.
it('has no HTTP client of its own: PSR-18 and nothing else', function (): void {
    $forbidden = '/\\b(GuzzleHttp|Symfony\\\\Component\\\\HttpClient|Nyholm|Http\\\\Client\\\\Common|Illuminate)\\\\/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        expect(preg_match($forbidden, (string) file_get_contents((string) $file)))->toBe(0, "{$file} uses an HTTP implementation");
    }

    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    expect(array_keys(is_array($composer) && is_array($composer['require']) ? $composer['require'] : []))
        ->not->toContain('guzzlehttp/guzzle', 'symfony/http-client', 'nyholm/psr7', 'php-http/client-common');
});

arch('no debugging left behind')
    ->preset()->php();

arch('every exception the toolbox throws can be caught as one type')
    ->expect('JustSteveKing\SdkToolbox\Errors')
    ->classes()
    ->extending(Throwable::class)
    ->toImplement(SdkException::class);

arch('values are immutable')
    ->expect([
        'JustSteveKing\SdkToolbox\Http',
        'JustSteveKing\SdkToolbox\Bodies',
        'JustSteveKing\SdkToolbox\Auth',
        'JustSteveKing\SdkToolbox\Redaction',
    ])
    ->classes()
    ->toBeReadonly()
    ->ignoring([
        'JustSteveKing\SdkToolbox\Http\Method',
        'JustSteveKing\SdkToolbox\Http\QueryString',
        'JustSteveKing\SdkToolbox\Http\StructuredFields',
        'JustSteveKing\SdkToolbox\Bodies\EncodesJson',
        'JustSteveKing\SdkToolbox\Bodies\JsonPointer',
    ]);
