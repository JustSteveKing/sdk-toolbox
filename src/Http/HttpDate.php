<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Http;

use DateTimeImmutable;
use DateTimeZone;

/**
 * HTTP-date (RFC 9110 section 5.6.7), in all three forms a recipient MUST
 * accept: IMF-fixdate, the obsolete RFC 850 form, and asctime.
 *
 * @internal
 */
final class HttpDate
{
    private const array FORMATS = [
        'D, d M Y H:i:s \G\M\T',
        'l, d-M-y H:i:s \G\M\T',
        'D M j H:i:s Y',
    ];

    public static function parse(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        foreach (self::FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }

        return null;
    }

    public static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T');
    }
}
