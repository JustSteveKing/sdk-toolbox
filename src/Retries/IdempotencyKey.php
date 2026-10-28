<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Retries;

use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;

/**
 * Idempotency-Key values: generated, and formatted for the header.
 */
enum IdempotencyKey
{
    /**
     * `Idempotency-Key: "8e03978e-..."`: a Structured Field String, as the
     * draft defines it (section 2.1).
     */
    case Quoted;

    /**
     * `Idempotency-Key: 8e03978e-...`: the bare token many APIs, Stripe
     * among them, have always taken.
     */
    case Bare;

    /**
     * A random UUID (version 4), as the draft recommends (section 2.2).
     */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function header(string $key): string
    {
        // An sf-string is printable ASCII, with `"` and `\` escaped (RFC 9651
        // section 3.3.3); a bare value can't hold quotes or spaces at all.
        if (preg_match('/^[\x20-\x7E]+$/', $key) !== 1 || ($this === self::Bare && preg_match('/[\s"]/', $key) === 1)) {
            throw new InvalidRequestException('An idempotency key must be printable ASCII, and a bare one without spaces or quotes.');
        }

        return match ($this) {
            self::Quoted => '"'.addcslashes($key, '"\\').'"',
            self::Bare => $key,
        };
    }
}
