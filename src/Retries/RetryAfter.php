<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Retries;

use JustSteveKing\SdkToolbox\Http\HttpDate;
use JustSteveKing\SdkToolbox\Time\Seconds;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads `Retry-After` (RFC 9110 section 10.2.3): seconds, or an HTTP-date.
 *
 * Dates are accepted in all three forms RFC 9110 section 5.6.7 says a
 * recipient must accept: IMF-fixdate, the obsolete RFC 850 form and
 * asctime. A date in the past means "now". Anything malformed is ignored.
 *
 * `retry-after-ms`, which some APIs send for sub-second waits, is read only
 * when `Retry-After` is absent: it isn't a standard, and the standard wins.
 */
final class RetryAfter
{
    /**
     * Seconds to wait, or null if the response doesn't say.
     */
    public static function from(ResponseInterface $response, ClockInterface $clock): ?float
    {
        if ($response->hasHeader('Retry-After')) {
            return self::parse($response->getHeaderLine('Retry-After'), $clock);
        }

        $milliseconds = trim($response->getHeaderLine('retry-after-ms'));

        return is_numeric($milliseconds) && (float) $milliseconds >= 0 ? (float) $milliseconds / 1000 : null;
    }

    public static function parse(string $value, ClockInterface $clock): ?float
    {
        $value = trim($value);

        // delay-seconds = 1*DIGIT: no sign, no fraction.
        if (preg_match('/^\d+$/', $value) === 1) {
            return (float) $value;
        }

        $date = HttpDate::parse($value);

        if ($date !== null) {
            return max(0.0, Seconds::between($clock->now(), $date));
        }

        return null;
    }
}
