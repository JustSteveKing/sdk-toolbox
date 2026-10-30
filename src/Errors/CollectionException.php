<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use LogicException;

/**
 * A collection was used in a way it can't support: written to, given an
 * operator it doesn't know, or asked to key by something that isn't a key.
 * A mistake in the calling code, never in the API's response.
 */
final class CollectionException extends LogicException implements SdkException
{
    public static function immutable(): self
    {
        return new self('Collections are immutable: map(), filter() and the rest return a new one.');
    }
}
