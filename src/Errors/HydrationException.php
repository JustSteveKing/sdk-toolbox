<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Errors;

use RuntimeException;
use Throwable;

/**
 * A response that came back fine but doesn't fit the type the SDK
 * expected: the API changed, or the SDK's model is wrong.
 */
final class HydrationException extends RuntimeException implements SdkException
{
    /**
     * @param  list<string>  $errors  one per field, as `path: what's wrong`
     */
    public function __construct(
        public readonly string $type,
        public readonly array $errors,
        ?Throwable $previous = null,
    ) {
        $shown = array_slice($errors, 0, 5);
        $more = count($errors) > 5 ? sprintf(' (and %d more)', count($errors) - 5) : '';

        parent::__construct(
            "The response doesn't fit {$type}: ".implode('; ', $shown).$more,
            previous: $previous,
        );
    }
}
