<?php

declare(strict_types=1);

use JustSteveKing\SdkToolbox\Retries\RetryAfter;
use JustSteveKing\SdkToolbox\Testing\FakeResponse;
use JustSteveKing\SdkToolbox\Testing\FakeTime;

/**
 * RFC 9110 section 10.2.3: Retry-After = HTTP-date / delay-seconds.
 */
it('reads delay-seconds', function (): void {
    expect(RetryAfter::parse('120', new FakeTime))->toBe(120.0);
});

it('reads all three HTTP-date forms (RFC 9110 5.6.7)', function (string $date): void {
    $now = new FakeTime('1994-11-06 08:49:00 UTC');

    expect(RetryAfter::parse($date, $now))->toBe(37.0);
})->with([
    'IMF-fixdate' => ['Sun, 06 Nov 1994 08:49:37 GMT'],
    'obsolete RFC 850' => ['Sunday, 06-Nov-94 08:49:37 GMT'],
    'asctime' => ['Sun Nov  6 08:49:37 1994'],
]);

it('treats a date in the past as now', function (): void {
    expect(RetryAfter::parse('Sun, 06 Nov 1994 08:49:37 GMT', new FakeTime))->toBe(0.0);
});

it('ignores what is neither', function (string $value): void {
    expect(RetryAfter::parse($value, new FakeTime))->toBeNull();
})->with(['negative' => ['-5'], 'fractional' => ['1.5'], 'words' => ['soon'], 'empty' => ['']]);

it('reads retry-after-ms only when Retry-After is absent', function (): void {
    expect(RetryAfter::from(FakeResponse::empty(429, ['retry-after-ms' => '250']), new FakeTime))->toBe(0.25)
        ->and(RetryAfter::from(FakeResponse::empty(429, ['retry-after-ms' => '250', 'Retry-After' => '3']), new FakeTime))->toBe(3.0);
});
