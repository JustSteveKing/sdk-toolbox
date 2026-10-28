<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Bodies;

use JsonException;
use JustSteveKing\SdkToolbox\Errors\InvalidRequestException;

/**
 * @internal
 */
final class EncodesJson
{
    public const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public static function encode(mixed $data): string
    {
        try {
            return json_encode($data, self::FLAGS);
        } catch (JsonException $e) {
            throw new InvalidRequestException("The request body can't be encoded as JSON: {$e->getMessage()}", previous: $e);
        }
    }
}
